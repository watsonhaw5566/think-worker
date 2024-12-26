<?php

namespace think\worker\concerns;

use think\App;
use think\worker\Pool;
use think\worker\Watcher;
use Workerman\Worker;

/**
 * Trait InteractsWithServer
 * @property App $container
 */
trait InteractsWithServer
{

    /**
     * @var array
     */
    protected $startFuncMap = [];

    protected $workerId;

    /** @var Pool */
    protected $pool;

    public function addWorker(callable $func): self
    {
        $this->startFuncMap[] = $func;
        return $this;
    }

    /**
     * 启动服务
     * @param string $envName 环境变量标识
     */
    public function start(string $envName): void
    {
        $this->initialize();
        $this->triggerEvent('init');

        //热更新
        if ($this->getConfig('hot_update.enable', false)) {
            $this->addHotUpdateProcess();
        }

        $pool = $this->createPool();

        $pool->onWorkerStart(function (Worker $worker) use ($envName) {

            $this->clearCache();
            $this->prepareApplication($envName);

            $this->triggerEvent('workerStart', $worker);
        });

        $pool->start();
    }

    public function getWorkerId()
    {
        return $this->workerId;
    }

    /**
     * 获取当前工作进程池对象
     * @return Pool
     */
    public function getPool()
    {
        return $this->pool;
    }

    protected function createPool()
    {
        return new Pool($this->startFuncMap);
    }

    /**
     * 热更新
     */
    protected function addHotUpdateProcess()
    {
        $this->addWorker(function () {
            $worker             = new Worker();
            $worker->name       = 'hot update';
            $worker->reloadable = false;

            $worker->onWorkerStart = function () {
                $watcher = $this->container->make(Watcher::class);
                $watcher->watch(function () {
                    posix_kill(posix_getppid(), SIGUSR1);
                });
            };

            return $worker;
        });
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
