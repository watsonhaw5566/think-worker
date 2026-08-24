<?php

declare(strict_types=1);

namespace think\worker\concerns;

use think\helper\Arr;
use think\worker\cron\Scheduler;
use think\worker\Task;
use Workerman\Timer;

/**
 * think-worker 内置 Cron 定时任务集成
 *
 * 特性：
 *   - 秒级调度（每 1s tick）
 *   - 独立单进程，worker_num=1，避免重复触发
 *   - 每次 tick 在 Sandbox 中执行，容器状态隔离
 *   - onOneServer / withoutOverlapping / tries / between / when / skip 全部可用
 *   - 任务注册方式：配置 tasks 数组 或 目录扫描 paths
 */
trait InteractsWithCron
{
    /** 当前 tick timer id（用于平滑停止） */
    protected ?int $cronTimerId = null;

    protected function prepareCron(): void
    {
        if (!$this->getConfig('cron.enable', false)) {
            return;
        }

        // 独立单进程（每秒 tick 一次，多进程会重复调度）
        $this->addWorker(function () {
            $this->startCronLoop();
        }, 'cron', 1);
    }

    /**
     * 启动 cron 每秒调度循环
     */
    protected function startCronLoop(): void
    {
        // 先立刻执行一次当前秒（不遗漏），再按 1s 间隔持续 tick
        $this->tickCronOnce();

        $this->cronTimerId = Timer::add(1, function () {
            $this->tickCronOnce();
        }, [], true);
    }

    /**
     * 单次 tick：构造 Scheduler → 加载任务 → 扫描 due → 执行
     */
    protected function tickCronOnce(): void
    {
        $nowTs = time();

        $this->runInSandbox(function () use ($nowTs) {
            /** @var Scheduler $scheduler */
            $scheduler = $this->app->make(Scheduler::class);

            $config = $this->getConfig('cron', []);

            // 方式一：从 worker.cron.tasks 读取
            $taskClasses = (array) Arr::get($config, 'tasks', []);
            $scheduler->addMany($taskClasses);

            // 方式二：目录自动扫描
            $paths = (array) Arr::get($config, 'paths', []);
            foreach ($paths as $namespace => $dir) {
                $this->scanTasksTo($scheduler, (string) $namespace, (string) $dir);
            }

            $this->triggerEvent('cron.tick_before', ['time' => $nowTs]);
            try {
                $scheduler->run($nowTs);
            } catch (\Throwable $e) {
                $this->logServerError($e);
            }
            $this->triggerEvent('cron.tick_after', ['time' => $nowTs]);
        });
    }

    /**
     * 扫描目录下继承自 think\worker\Task 的类并注册到 Scheduler
     */
    protected function scanTasksTo(Scheduler $scheduler, string $namespace, string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative  = ltrim(substr($file->getPathname(), strlen($path)), DIRECTORY_SEPARATOR);
            $className = $namespace . '\\' . str_replace(
                [DIRECTORY_SEPARATOR, '.php'],
                ['\\', ''],
                $relative
            );

            if (!class_exists($className)) {
                require_once $file->getPathname();
                if (!class_exists($className)) {
                    continue;
                }
            }

            if (is_subclass_of($className, Task::class)) {
                $ref = new \ReflectionClass($className);
                if ($ref->isAbstract()) {
                    continue;
                }
                $scheduler->add($className);
            }
        }
    }
}
