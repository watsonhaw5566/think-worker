<?php

namespace think\worker;

class Worker extends \Workerman\Worker
{
    public static function runAll()
    {
        static::init();
        static::lock();
        static::daemonize();
        static::initWorkers();
        static::installSignal();
        static::saveMasterPid();
        static::lock(\LOCK_UN);
        static::displayUI();
        static::forkWorkers();
        static::resetStd();
        static::monitorWorkers();
    }
}
