<?php

namespace think\worker\concerns;

use think\App;
use think\worker\Watcher;
use think\worker\Worker;

/**
 * Trait InteractsWithServer
 * @property App $container
 */
trait InteractsWithServer
{

    public function addWorker(callable $func, $name = 'none', int $count = 1): Worker
    {
        $worker = new Worker();

        $worker->name  = $name;
        $worker->count = $count;

        $worker->onWorkerStart = function (Worker $worker) use ($func) {
            $this->clearCache();
            $this->prepareApplication();

            $this->triggerEvent('workerStart', $worker);

            $func($worker);
        };

        return $worker;
    }

    /**
     * 启动服务
     */
    public function start(): void
    {
        $this->initialize();
        $this->triggerEvent('init');

        //热更新
        if ($this->getConfig('hot_update.enable', false)) {
            $this->addHotUpdateWorker();
        }

        Worker::runAll();
    }

    /**
     * 热更新
     */
    protected function addHotUpdateWorker()
    {
        $worker = $this->addWorker(function () {
            $watcher = $this->container->make(Watcher::class);
            $watcher->watch(function () {
                posix_kill(posix_getppid(), SIGUSR1);
            });
        }, 'hot update');

        $worker->reloadable = false;
    }

    /**
     * 清除apc、op缓存
     */
    protected function clearCache()
    {
        if (extension_loaded('apc')) {
            apc_clear_cache();
        }

        if (extension_loaded('Zend OPcache')) {
            opcache_reset();
        }
    }

}
