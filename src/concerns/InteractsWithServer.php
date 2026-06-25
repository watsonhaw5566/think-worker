<?php

namespace think\worker\concerns;

use think\App;
use think\worker\Ipc;
use think\worker\Watcher;
use think\worker\Worker;
use Workerman\Redis\Client;

/**
 * Trait InteractsWithServer
 * @property App $container
 */
trait InteractsWithServer
{
    protected Ipc $ipc;

    protected mixed $workerId = null;
    protected bool $stopping = false;

    public function addWorker(callable $func, string $name = 'none', int $count = 1): Worker
    {
        $worker = new Worker();

        $worker->name  = $name;
        $worker->count = $count;

        $worker->onWorkerStart = function (Worker $worker) use ($func) {
            $this->clearCache();
            $this->prepareApplication();

            $this->conduit->connect();

            $this->workerId = $this->ipc->listenMessage();

            $this->triggerEvent('workerStart', $worker);

            $func($worker);
        };

        $worker->onWorkerReload = function () {
            $this->stopping = true;
        };

        return $worker;
    }

    public function getWorkerId(): mixed
    {
        return $this->workerId;
    }

    public function isStopping(): bool
    {
        return $this->stopping;
    }

    /**
     * 启动服务
     */
    public function start(): void
    {
        $this->initialize();
        $this->prepareIpc();
        $this->triggerEvent('init');

        //热更新
        if ($this->getConfig('hot_update.enable', false)) {
            $this->addHotUpdateWorker();
        }

        Worker::runAll();
    }

    protected function prepareIpc(): void
    {
        $this->ipc = $this->container->make(Ipc::class);
    }

    public function sendMessage(mixed $workerId, mixed $message): void
    {
        $this->ipc->sendMessage($workerId, $message);
    }

    /**
     * 热更新
     */
    protected function addHotUpdateWorker(): void
    {
        $worker = new Worker();

        $worker->name       = 'hot update';
        $worker->reloadable = false;

        $worker->onWorkerStart = function () {
            $watcher = $this->container->make(Watcher::class);
            $watcher->watch(function () {
                posix_kill(posix_getppid(), SIGUSR1);
            });
        };
    }

    /**
     * 清除apc、op缓存
     */
    protected function clearCache(): void
    {
        if (extension_loaded('apc')) {
            apc_clear_cache();
        }

        if (extension_loaded('Zend OPcache')) {
            opcache_reset();
        }
    }

}