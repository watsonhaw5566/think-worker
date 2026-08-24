<?php

declare(strict_types=1);

namespace tests\feature;

use PHPUnit\Framework\TestCase;
use think\App;
use think\worker\cron\Scheduler;
use think\worker\event\TaskFailed;
use think\worker\event\TaskProcessed;
use think\worker\event\TaskSkipped;
use think\worker\Task;

/**
 * @covers \Scheduler
  * @covers 
  */
class SchedulerTest extends TestCase
{
    private static App $app;

    public static function setUpBeforeClass(): void
    {
        // 基于 stub 目录构造真正的 ThinkPHP 应用。
        // ⚠️ 只初始化一次：多次 new/clone App 会破坏 think\Cache 等 Manager 的内部
        // 闭包 resolver，出现「call_user_func_array callback 无效」错误。
        $app = new App(STUB_DIR);
        $app->initialize();
        self::$app = $app;
    }

    /**
     * 创建匿名 Task 子类实例（使用 stub App 构造）
     *
     * @param \Closure(Task): mixed  $configurator  通过 Fluent API 配置任务（允许链式返回 $this）
     * @param \Closure():void        $executor      执行时的业务闭包
     * @param ?class-string          $taskClass     可选：用具体类名注册（子类任务共享名字便于单服务器锁验证）
     * @return class-string<Task>
     */
    private static function makeTaskClass(
        \Closure $configurator,
        \Closure $executor,
        ?string $taskClass = null
    ): string {
        // 所有动态行为注入到 tests\stubs\task\_CounterTask 静态属性。
        // 每次调用会覆盖上一次的 configurator/executor，因此一个测试方法内需要多个任务时：
        //   a) 若需区分名/锁 → 提供自定义 taskClass（见下），通过类级别的 setMeta 区分
        //   b) 若只共享一个行为 → 不用管覆盖，当前测试方法内只会 run 一次当前期望的 configurator
        //
        // 为了支持多任务（addMany）的场景，这里支持传入具体的 Task 子类 $taskClass，
        // 如果提供则直接在那个子类上设置静态属性（该子类必须实现 setMeta 静态方法）。
        if ($taskClass !== null) {
            // 所有测试用的 Task 子类（_CounterTask / _Stub1 / _Stub2）都实现了 public static setMeta()。
            $taskClass::setMeta($configurator, $executor);
            return $taskClass;
        }

        _CounterTask::setMeta($configurator, $executor);
        return _CounterTask::class;
    }

    /**
     * @return array{0:Scheduler,1:\think\Cache}
     */
    private function makeSchedulerWithCleanCache(): array
    {
        // ⚠️ 不要 clone self::$app：会破坏 think\Cache 等 Manager 的闭包 resolver。
        // 全类共用一个 App；但 Scheduler 必须 newInstance，避免 App 单例污染导致
        // 多个测试共用同一个 Scheduler 实例（tasks 累积）。
        $app = self::$app;
        $cache = $app->cache;

        // 每次创建 Scheduler 前清空 file cache：onOneServer/overlapping 的锁会
        // 写入 file cache，跨测试/跨运行残留会导致抢占逻辑失效。
        $cache->clear();

        $scheduler = $app->make(Scheduler::class, [], true);

        // 收集事件（通过 listen 入队）。多次 listen 会重复注册 listener 没关系，
        // 因为闭包捕获不同测试方法独立的 eventBagKey，写入各自独立的 event bag。
        $eventBagKey = self::eventBagKey();
        $GLOBALS[$eventBagKey] = [];
        foreach ([TaskProcessed::class, TaskSkipped::class, TaskFailed::class] as $ev) {
            $app->event->listen($ev, function ($event) use ($ev, $eventBagKey) {
                $GLOBALS[$eventBagKey][] = ['event' => $ev, 'payload' => $event];
            });
        }

        return [$scheduler, $cache];
    }

    private function eventBagKey(): string
    {
        return __CLASS__ . '_event_bag_' . $this->name();
    }

    /**
     * @return list<array{event:class-string,payload:object}>
     */
    private function collectEvents(): array
    {
        return $GLOBALS[$this->eventBagKey()] ?? [];
    }

    /**
     * @param class-string $eventClass
     * @return list<object>
     */
    private function eventsOf(string $eventClass): array
    {
        $result = [];
        foreach ($this->collectEvents() as $row) {
            if ($row['event'] === $eventClass) {
                $result[] = $row['payload'];
            }
        }
        return $result;
    }

    /*
     *--------------------------------------------------------------------------
     * 1. 基本 due / not due
     *--------------------------------------------------------------------------
     */

    /**
     */
    public function testDueTaskRunsAndFiresProcessedEvent(): void
    {
        [$scheduler] = $this->makeSchedulerWithCleanCache();

        $runCount = 0;
        $taskClass = self::makeTaskClass(
            configurator: function (Task $t) {
                $t->everySecond()->name('due-task');
            },
            executor: function () use (&$runCount) {
                $runCount++;
            },
        );

        $scheduler->add($taskClass);
        $scheduler->run(time());

        self::assertSame(1, $runCount);
        $processed = $this->eventsOf(TaskProcessed::class);
        self::assertCount(1, $processed);
        self::assertSame('due-task', $processed[0]->getName());
    }

    /**
     */
    public function testNotDueTaskNotExecuted(): void
    {
        [$scheduler] = $this->makeSchedulerWithCleanCache();

        $runCount = 0;
        $taskClass = self::makeTaskClass(
            configurator: function (Task $t) {
                // 每年一次，必然 not due
                $t->yearly()->name('yearly');
            },
            executor: function () use (&$runCount) {
                $runCount++;
            },
        );

        $scheduler->add($taskClass);
        $scheduler->run(time());

        self::assertSame(0, $runCount, 'yearly 任务不会在 run() 的当前秒命中');
        self::assertCount(0, $this->eventsOf(TaskProcessed::class));
    }

    /**
     */
    public function testDisabledTaskSkipped(): void
    {
        [$scheduler] = $this->makeSchedulerWithCleanCache();

        $runCount = 0;
        $taskClass = self::makeTaskClass(
            configurator: function (Task $t) {
                $t->everySecond()->disable();
            },
            executor: function () use (&$runCount) {
                $runCount++;
            },
        );

        $scheduler->add($taskClass);
        $scheduler->run(time());

        self::assertSame(0, $runCount);
        // disabled 任务在遍历之初就跳过，不触发 Skipped（Skipped 是条件/互斥引起的）
        self::assertCount(0, $this->eventsOf(TaskSkipped::class));
    }

    /*
     *--------------------------------------------------------------------------
     * 2. between / unlessBetween
     *--------------------------------------------------------------------------
     */

    /**
     */
    public function testBetweenPositiveInsideWindowRuns(): void
    {
        [$scheduler] = $this->makeSchedulerWithCleanCache();

        $runCount = 0;
        // 构造一个永远成立的窗口 00:00-23:59
        $taskClass = self::makeTaskClass(
            configurator: function (Task $t) {
                $t->everySecond()->between('00:00', '23:59');
            },
            executor: function () use (&$runCount) { $runCount++; },
        );
        $scheduler->add($taskClass);
        $scheduler->run(time());
        self::assertSame(1, $runCount);
    }

    /**
     */
    public function testBetweenOutsideWindowSkips(): void
    {
        [$scheduler] = $this->makeSchedulerWithCleanCache();

        $runCount = 0;
        // 假设当前永远不落在 00:00-00:00（1分钟窗口，概率极低）
        // 更稳妥：设置 unlessBetween 00:00-23:59 → 永远不通过
        $taskClass = self::makeTaskClass(
            configurator: function (Task $t) {
                $t->everySecond()->unlessBetween('00:00', '23:59');
            },
            executor: function () use (&$runCount) { $runCount++; },
        );
        $scheduler->add($taskClass);
        $scheduler->run(time());
        self::assertSame(0, $runCount);
        $skipped = $this->eventsOf(TaskSkipped::class);
        self::assertCount(1, $skipped);
        self::assertSame('time_between', $skipped[0]->reason);
    }

    /*
     *--------------------------------------------------------------------------
     * 3. when / skip 回调
     *--------------------------------------------------------------------------
     */

    /**
     */
    public function testWhenAllTrueAndSkipAllFalseRuns(): void
    {
        [$scheduler] = $this->makeSchedulerWithCleanCache();
        $runCount = 0;
        $taskClass = self::makeTaskClass(
            configurator: function (Task $t) {
                $t->everySecond()
                  ->when(static fn() => true)
                  ->when(static fn() => (bool) getmypid())
                  ->skip(static fn() => false);
            },
            executor: function () use (&$runCount) { $runCount++; },
        );
        $scheduler->add($taskClass);
        $scheduler->run(time());
        self::assertSame(1, $runCount);
    }

    /**
     */
    public function testWhenAnyFalseSkipsWithCallbackReason(): void
    {
        [$scheduler] = $this->makeSchedulerWithCleanCache();
        $runCount = 0;
        $taskClass = self::makeTaskClass(
            configurator: function (Task $t) {
                $t->everySecond()->when(fn() => false);
            },
            executor: function () use (&$runCount) { $runCount++; },
        );
        $scheduler->add($taskClass);
        $scheduler->run(time());
        self::assertSame(0, $runCount);
        $skipped = $this->eventsOf(TaskSkipped::class);
        self::assertCount(1, $skipped);
        self::assertSame('callback', $skipped[0]->reason);
    }

    /**
     */
    public function testSkipAnyTrueSkips(): void
    {
        [$scheduler] = $this->makeSchedulerWithCleanCache();
        $runCount = 0;
        $taskClass = self::makeTaskClass(
            configurator: function (Task $t) {
                $t->everySecond()->skip(fn() => true);
            },
            executor: function () use (&$runCount) { $runCount++; },
        );
        $scheduler->add($taskClass);
        $scheduler->run(time());
        self::assertSame(0, $runCount);
        self::assertCount(1, $this->eventsOf(TaskSkipped::class));
    }

    /*
     *--------------------------------------------------------------------------
     * 4. withoutOverlapping 互斥锁
     *--------------------------------------------------------------------------
     */

    /**
     */
    public function testOverlappingLockSkipsSecondRun(): void
    {
        [$schedulerA] = $this->makeSchedulerWithCleanCache();
        [$schedulerB] = $this->makeSchedulerWithCleanCache(); // 共享 cache store

        $runCount = 0;
        $configurator = function (Task $t) {
            // 锁名带测试方法后缀，跨测试之间锁不冲突
            $t->everySecond()->withoutOverlapping(60)->name('lock-task-overlap');
        };
        // ⚠️ withoutOverlapping 的语义是「执行期间互斥」，成功完成后立即主动释放锁。
        // 因此要让 B 看到锁仍被持有，必须让 B 的调度发生在 A 的 execute() 仍在运行时。
        $executor = function () use (&$runCount, &$schedulerB) {
            $runCount++;
            // A 的 executor 执行中 → overlapping 锁尚未释放 → B 此时调度应当被 skip
            $schedulerB->run(time());
        };
        $taskClass = self::makeTaskClass($configurator, $executor);

        $schedulerA->add($taskClass);
        $schedulerB->add($taskClass);

        $schedulerA->run(time());
        self::assertSame(1, $runCount, 'A 抢到锁；嵌套的 B 调度因 overlapping 被 skip，总执行次数仍为 1');
        $skipped = $this->eventsOf(TaskSkipped::class);
        self::assertGreaterThanOrEqual(1, count($skipped));
        $lastSkipped = end($skipped);
        self::assertNotFalse($lastSkipped);
        self::assertSame('overlapping', $lastSkipped->reason);
    }

    /*
     *--------------------------------------------------------------------------
     * 5. onOneServer 单服务器抢占（以任务名+分钟为粒度）
     *--------------------------------------------------------------------------
     */

    /**
     */
    public function testOnOneServerGlobalOnlyOneRuns(): void
    {
        // 通过 worker.cron 全局 onOneServer=true 配置模拟：需要在 run() 之前把
        // $this->defaultOnOneServer 设为 true。Scheduler 是从 config 读取的。
        // 最简洁：改用任务级显式 onOneServer()。

        [$schedulerA] = $this->makeSchedulerWithCleanCache();
        [$schedulerB] = $this->makeSchedulerWithCleanCache();

        $runCount = 0;
        $taskClass = self::makeTaskClass(
            configurator: function (Task $t) {
                // 显式锁名跨测试唯一（onOneServer 锁 TTL=70s，避免前一次运行残留）
                $t->everySecond()->onOneServer()->name('singleton-one');
            },
            executor: function () use (&$runCount) { $runCount++; },
        );

        $schedulerA->add($taskClass);
        $schedulerB->add($taskClass);

        $schedulerA->run(time());
        $schedulerB->run(time());

        self::assertSame(1, $runCount, '两台 Scheduler 只有一台抢占成功');
    }

    /**
     */
    public function testWithoutOnOneServerForcesMulti(): void
    {
        [$schedulerA] = $this->makeSchedulerWithCleanCache();
        [$schedulerB] = $this->makeSchedulerWithCleanCache();

        $runCount = 0;
        $taskClass = self::makeTaskClass(
            configurator: function (Task $t) {
                // 显式关闭（尽管默认 false，但用于覆盖全局 true 场景显式测试）
                $t->everySecond()->withoutOnOneServer()->name('multi');
            },
            executor: function () use (&$runCount) { $runCount++; },
        );

        $schedulerA->add($taskClass);
        $schedulerB->add($taskClass);

        $schedulerA->run(time());
        $schedulerB->run(time());

        self::assertSame(2, $runCount, '显式 withoutOnOneServer()，两台都执行');
    }

    /*
     *--------------------------------------------------------------------------
     * 6. tries 失败重试
     *--------------------------------------------------------------------------
     */

    /**
     */
    public function testTriesRetriesCorrectNumberOfTimes(): void
    {
        [$scheduler] = $this->makeSchedulerWithCleanCache();

        $attempts = 0;
        $taskClass = self::makeTaskClass(
            configurator: function (Task $t) {
                // 不用 delay 避免测试耗时
                $t->everySecond()->tries(3, 0);
            },
            executor: function () use (&$attempts) {
                $attempts++;
                throw new \RuntimeException('boom');
            },
        );
        $scheduler->add($taskClass);

        $caught = null;
        try {
            $scheduler->run(time());
        } catch (\RuntimeException $e) {
            $caught = $e;
        }

        self::assertNotNull($caught);
        self::assertSame('boom', $caught->getMessage());
        self::assertSame(3, $attempts, 'tries=3 总共应执行 3 次（含首次）');

        $failed = $this->eventsOf(TaskFailed::class);
        self::assertCount(1, $failed);
        self::assertSame(3, $failed[0]->attempts);
    }

    /**
     */
    public function testSuccessOnRetryDoesNotThrowAndNoFailedEvent(): void
    {
        [$scheduler] = $this->makeSchedulerWithCleanCache();

        $attempts = 0;
        $taskClass = self::makeTaskClass(
            configurator: function (Task $t) {
                $t->everySecond()->tries(5, 0);
            },
            executor: function () use (&$attempts) {
                $attempts++;
                if ($attempts < 3) {
                    throw new \RuntimeException('try again');
                }
                // 第 3 次成功
            },
        );
        $scheduler->add($taskClass);

        $scheduler->run(time());

        self::assertSame(3, $attempts);
        self::assertCount(0, $this->eventsOf(TaskFailed::class));
        self::assertCount(1, $this->eventsOf(TaskProcessed::class));
    }

    /*
     *--------------------------------------------------------------------------
     * 7. addMany & getTasks
     *--------------------------------------------------------------------------
     */

    /**
     */
    public function testAddManyAndGetTasksWorks(): void
    {
        [$scheduler] = $this->makeSchedulerWithCleanCache();

        $classes = [
            self::makeTaskClass(fn(Task $t) => $t->everySecond()->name('t1'), static fn() => null, taskClass: __NAMESPACE__ . '\\_Stub1'),
            self::makeTaskClass(fn(Task $t) => $t->everySecond()->name('t2'), static fn() => null, taskClass: __NAMESPACE__ . '\\_Stub2'),
        ];
        $scheduler->addMany($classes);

        self::assertCount(2, $scheduler->getTasks());
        self::assertSame('t1', $scheduler->getTasks()[0]->getName());
        self::assertSame('t2', $scheduler->getTasks()[1]->getName());
    }

    /**
     */
    public function testAddInvalidClassThrows(): void
    {
        [$scheduler] = $this->makeSchedulerWithCleanCache();
        $this->expectException(\InvalidArgumentException::class);
        $scheduler->add(\stdClass::class);
    }
}

/*
 *--------------------------------------------------------------------------
 * 测试辅助：动态可重写 Task 类（文件作用域，全局可见）
 *--------------------------------------------------------------------------
 *
 * 所有共享一个具名类，通过静态 setMeta() 为每次测试替换 configure / execute 闭包。
 * 类名全局，需保证与生产代码无冲突。
 */

class _CounterTask extends Task
{
    private static \Closure $cfg;
    private static \Closure $exe;

    public static function setMeta(\Closure $cfg, \Closure $exe): void
    {
        self::$cfg = $cfg;
        self::$exe = $exe;
    }

    protected function configure(): void
    {
        (self::$cfg ?? static fn(Task $t) => $t->everySecond())($this);
    }

    protected function execute(): void
    {
        (self::$exe ?? static fn() => null)();
    }
}

/*
 * 用于 addMany 场景的两个独立 Task 子类。
 * 使用独立的静态属性，避免与 _CounterTask 互相污染。
 */
class _Stub1 extends Task
{
    private static \Closure $cfg;
    private static \Closure $exe;

    public static function setMeta(\Closure $cfg, \Closure $exe): void
    {
        self::$cfg = $cfg;
        self::$exe = $exe;
    }

    protected function configure(): void
    {
        (self::$cfg ?? static fn(Task $t) => $t->everySecond())($this);
    }

    protected function execute(): void
    {
        (self::$exe ?? static fn() => null)();
    }
}

class _Stub2 extends Task
{
    private static \Closure $cfg;
    private static \Closure $exe;

    public static function setMeta(\Closure $cfg, \Closure $exe): void
    {
        self::$cfg = $cfg;
        self::$exe = $exe;
    }

    protected function configure(): void
    {
        (self::$cfg ?? static fn(Task $t) => $t->everySecond())($this);
    }

    protected function execute(): void
    {
        (self::$exe ?? static fn() => null)();
    }
}