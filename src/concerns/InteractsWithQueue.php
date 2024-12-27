<?php

namespace think\worker\concerns;

use think\helper\Arr;
use think\queue\event\JobFailed;
use think\queue\Worker;
use Workerman\Timer;

trait InteractsWithQueue
{
    protected function createQueueWorkers()
    {
        $workers = $this->getConfig('queue.workers', []);

        foreach ($workers as $queue => $options) {

            if (strpos($queue, '@') !== false) {
                [$queue, $connection] = explode('@', $queue);
            } else {
                $connection = null;
            }

            $workerNum = Arr::get($options, 'worker_num', 1);

            $this->addWorker(function (\think\worker\Worker $worker) use ($options, $connection, $queue) {
                $delay   = Arr::get($options, 'delay', 0);
                $sleep   = Arr::get($options, 'sleep', 3);
                $tries   = Arr::get($options, 'tries', 0);
                $timeout = Arr::get($options, 'timeout', 60);

                $qWorker = $this->app->make(Worker::class);

                while (true) {
                    $timer = Timer::add($timeout, function () use ($worker) {
                        $worker->stop();
                    }, [], false);

                    $this->runInSandbox(function () use ($connection, $queue, $delay, $sleep, $tries, $qWorker) {
                        $qWorker->runNextJob($connection, $queue, $delay, $sleep, $tries);
                    });

                    Timer::del($timer);
                }
            }, "queue [$queue]", $workerNum);
        }
    }

    public function prepareQueue()
    {
        if ($this->getConfig('queue.enable', false)) {
            $this->listenForEvents();
            $this->createQueueWorkers();
        }
    }

    /**
     * 注册事件
     */
    protected function listenForEvents()
    {
        $this->container->event->listen(JobFailed::class, function (JobFailed $event) {
            $this->logFailedJob($event);
        });
    }

    /**
     * 记录失败任务
     * @param JobFailed $event
     */
    protected function logFailedJob(JobFailed $event)
    {
        $this->container['queue.failer']->log(
            $event->connection,
            $event->job->getQueue(),
            $event->job->getRawBody(),
            $event->exception
        );
    }
}
