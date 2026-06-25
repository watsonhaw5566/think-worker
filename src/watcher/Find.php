<?php

namespace think\worker\watcher;

use Symfony\Component\Process\Process;
use Workerman\Timer;

class Find implements Driver
{
    protected $name;
    protected $directory;
    protected $exclude;

    public function __construct($directory, $exclude, $name)
    {
        $this->directory = $directory;
        $this->exclude   = $exclude;
        $this->name      = $name;
    }

    public function watch(callable $callback)
    {
        $ms      = 2000;
        $minutes = 1;

        // 构造 Process 参数数组（不通过 shell 解析，从根本上避免命令注入）
        $processArgs = $this->buildProcessArgs($minutes);

        Timer::add($ms / 1000, function () use ($callback, $processArgs) {
            $stdout = $this->exec($processArgs);
            if (!empty($stdout)) {
                call_user_func($callback);
            }
        });
    }

    protected function buildProcessArgs($minutes)
    {
        $args = ['find'];

        // 搜索目录
        foreach ($this->directory as $dir) {
            $args[] = $dir;
        }

        // -name 匹配（一个或多个 pattern）
        if (!empty($this->name)) {
            if (count($this->name) === 1) {
                $args[] = '-name';
                $args[] = reset($this->name);
            } else {
                $args[] = '(';
                foreach ($this->name as $i => $pattern) {
                    if ($i > 0) $args[] = '-o';
                    $args[] = '-name';
                    $args[] = $pattern;
                }
                $args[] = ')';
            }
        }

        // exclude 目录
        if (!empty($this->exclude)) {
            $excludeDirs = [];
            foreach ($this->exclude as $directory) {
                $directory = rtrim($directory, '/');
                if (is_dir($directory)) {
                    $excludeDirs[] = $directory;
                }
            }

            if (!empty($excludeDirs)) {
                $args[] = '!';
                $args[] = '(';
                foreach ($excludeDirs as $i => $dir) {
                    if ($i > 0) $args[] = '-o';
                    $args[] = '-path';
                    $args[] = $dir . '/*';
                }
                $args[] = ')';
            }
        }

        // 其他固定参数
        $args[] = '-mmin';
        $args[] = '-' . $minutes;
        $args[] = '-type';
        $args[] = 'f';
        $args[] = '-print';

        return $args;
    }

    public function exec($processArgs)
    {
        $process = new Process($processArgs);
        $process->run();
        if ($process->isSuccessful()) {
            return $process->getOutput();
        }
        // 记录错误以便调试
        $error = $process->getErrorOutput();
        if (!empty($error)) {
            error_log('[think-worker] Find command error: ' . trim($error));
        }
        return false;
    }

}