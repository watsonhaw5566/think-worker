<?php

namespace think\worker;

use Workerman\Worker;

class Pool
{
    /** @var Worker[] */
    protected $workers;

    protected $onWorkerStart = null;

    public function __construct($startFuncMap)
    {
        $this->workers = array_map(function ($func) {
            return $func();
        }, $startFuncMap);
    }

    public function onWorkerStart(callable $callback)
    {
        $this->onWorkerStart = $callback;
        return $this;
    }

    public function start()
    {
        foreach ($this->workers as $worker) {
            if ($worker instanceof Worker) {
                $onWorkerStart = $worker->onWorkerStart;

                $worker->onWorkerStart = function (Worker $worker) use ($onWorkerStart) {
                    if (isset($this->onWorkerStart)) {
                        call_user_func($this->onWorkerStart, $worker);
                    }
                    if (isset($onWorkerStart)) {
                        $onWorkerStart($worker);
                    }
                };
            }
        }

        \think\worker\Worker::runAll();
    }
}
