<?php

declare(strict_types=1);

namespace think\worker\cron;

use think\App;
use think\Cache;
use think\cache\Driver as CacheDriver;
use think\worker\event\TaskFailed;
use think\worker\event\TaskProcessed;
use think\worker\event\TaskSkipped;
use think\worker\Task;

/**
 * Crontab 调度器（秒级）
 *
 * 每秒被调用一次 run()：
 *   1. 遍历所有 Task
 *   2. 根据 cron 表达式判断是否 due
 *   3. 检查 between / when / skip 条件
 *   4. onOneServer 原子抢占
 *   5. withoutOverlapping 互斥锁
 *   6. 执行（带失败重试）
 *   7. 触发对应事件
 */
class Scheduler
{
    /** @var Task[] */
    private array $tasks = [];

    private CacheDriver $store;

    private App $app;

    /** 全局 onOneServer 默认值（任务显式覆盖优先） */
    private bool $defaultOnOneServer;

    /** 全局默认 tries（任务显式覆盖优先） */
    private int $defaultTries;

    public function __construct(App $app, Cache $cache)
    {
        $this->app      = $app;
        $cronConfig     = $app->config->get('worker.cron', []);

        $storeName      = $cronConfig['store'] ?? null;
        $this->store    = $storeName ? $cache->store($storeName) : $cache->store();

        $this->defaultOnOneServer = (bool) ($cronConfig['onOneServer'] ?? false);
        $this->defaultTries       = (int) ($cronConfig['tries'] ?? 1);
    }

    /** 注册一个任务类 */
    public function add(string $taskClass): self
    {
        if (!is_subclass_of($taskClass, Task::class)) {
            throw new \InvalidArgumentException(
                "Task class {$taskClass} must extend " . Task::class
            );
        }
        // 强制每次独立实例：Task 的 configure() / expression / meta 等状态是实例级别的，
        // 不同调度器/不同注册之间不应共享实例，避免单例污染。
        $task = $this->app->make($taskClass, [], true);

        // 如果调用者未显式设置 name（仍为默认的 FQCN），追加一次性随机后缀，
        // 保证互斥锁（withoutOverlapping/onOneServer）的 key 全局唯一，避免在
        // 测试 / 复杂调度场景中同名任务之间的锁互相干扰。
        if ($task->getName() === $taskClass) {
            $task->name($taskClass . '_' . substr(bin2hex(random_bytes(5)), 0, 10));
        }

        $this->tasks[] = $task;
        return $this;
    }

    /**
     * 批量注册
     *
     * @param iterable<int,string> $taskClasses
     */
    public function addMany(iterable $taskClasses): self
    {
        foreach ($taskClasses as $cls) {
            $this->add($cls);
        }
        return $this;
    }

    /**
     * @return Task[]
     */
    public function getTasks(): array
    {
        return $this->tasks;
    }

    /**
     * 以指定时间戳为「当前秒」执行一次调度（每秒调用一次）
     *
     * 内部对每个任务的尝试循环做了异常处理；单个任务在全部重试失败后会通过
     * TaskFailed 事件上报并继续向外抛出异常，交由外层 Sandbox 的异常处理器记录日志。
     *
     * @throws \Throwable 任何任务在经过所有重试仍失败时抛出
     */
    public function run(int $nowTs): void
    {
        foreach ($this->tasks as $task) {
            if (!$task->isEnabled()) {
                continue;
            }

            $expression = new CronExpression($task->getExpression());
            $tz         = $task->getTimezone() ? new \DateTimeZone($task->getTimezone()) : null;

            if (!$expression->isDue($nowTs, $tz)) {
                continue;
            }

            // --- 时间区间 between / unlessBetween ---
            if (!$this->passesBetweenRules($task, $nowTs, $tz)) {
                $this->app->event->trigger(new TaskSkipped($task, 'time_between'));
                continue;
            }

            // --- when / skip 回调 ---
            if (!$this->passesCallbacks($task)) {
                $this->app->event->trigger(new TaskSkipped($task, 'callback'));
                continue;
            }

            $name = $task->getName();
            $mmss = date('Hi', $nowTs);

            // --- onOneServer：同一分钟内只有一台机器抢占成功 ---
            if ($this->shouldRunOnOneServer($task)) {
                $lockKey = "think_worker_cron:one_server:{$name}:{$mmss}";
                if (!$this->atomicSet($lockKey, 70)) {
                    $this->app->event->trigger(new TaskSkipped($task, 'single_server'));
                    continue;
                }
            }

            // --- withoutOverlapping：执行过程互斥 ---
            $overlapKey = null;
            if ($task->getLockExpireSec() > 0) {
                $overlapKey = "think_worker_cron:overlap:{$name}";
                if (!$this->atomicSet($overlapKey, $task->getLockExpireSec())) {
                    $this->app->event->trigger(new TaskSkipped($task, 'overlapping'));
                    continue;
                }
            }

            // --- 执行 + 失败重试 ---
            $maxAttempts = max(1, $task->getTries() ?: $this->defaultTries);
            $delay       = $task->getRetryDelaySec();
            $attempts    = 0;

            while (true) {
                $attempts++;
                try {
                    $task->run();
                    $this->app->event->trigger(new TaskProcessed($task));
                    break;
                } catch (\Throwable $e) {
                    if ($attempts >= $maxAttempts) {
                        $this->app->event->trigger(new TaskFailed($task, $e, $attempts));
                        if ($overlapKey !== null) {
                            $this->store->delete($overlapKey);
                        }
                        throw $e;
                    }
                    if ($delay > 0) {
                        sleep($delay);
                    }
                }
            }

            // 成功后主动释放重叠锁（失败路径已在 catch 中 throw 前释放）
            if ($overlapKey !== null) {
                $this->store->delete($overlapKey);
            }
        }
    }

    /*
     *--------------------------------------------------------------------------
     * 内部辅助
     *--------------------------------------------------------------------------
     */

    private function shouldRunOnOneServer(Task $task): bool
    {
        $v = $task->getOnOneServer();
        return $v === null ? $this->defaultOnOneServer : $v;
    }

    private function atomicSet(string $key, int $ttl): bool
    {
        if (method_exists($this->store, 'add')) {
            return $this->store->add($key, '1', $ttl);
        }

        if ($this->store->get($key) !== null) {
            return false;
        }
        $this->store->set($key, '1', $ttl);
        return true;
    }

    private function passesBetweenRules(Task $task, int $nowTs, ?\DateTimeZone $tz): bool
    {
        $rules = $task->getBetweenRules();
        if ($rules === []) {
            return true;
        }

        $dt = new \DateTimeImmutable('@' . $nowTs);
        if ($tz !== null) {
            $dt = $dt->setTimezone($tz);
        }
        $nowMin = (int) $dt->format('H') * 60 + (int) $dt->format('i');

        foreach ($rules as [$start, $end, $positive]) {
            [$sH, $sM] = array_map('intval', explode(':', $start)) + [0, 0];
            [$eH, $eM] = array_map('intval', explode(':', $end))   + [0, 0];
            $startMin  = $sH * 60 + $sM;
            $endMin    = $eH * 60 + $eM;

            if ($startMin <= $endMin) {
                $inside = $nowMin >= $startMin && $nowMin <= $endMin;
            } else {
                // 跨午夜：22:00 -> 01:00
                $inside = $nowMin >= $startMin || $nowMin <= $endMin;
            }

            if (!$positive) {
                $inside = !$inside;
            }

            if (!$inside) {
                return false;
            }
        }
        return true;
    }

    private function passesCallbacks(Task $task): bool
    {
        foreach ($task->getSkipCallbacks() as $cb) {
            if ($cb($this->app) === true) {
                return false;
            }
        }
        foreach ($task->getWhenCallbacks() as $cb) {
            if ($cb($this->app) !== true) {
                return false;
            }
        }
        return true;
    }
}
