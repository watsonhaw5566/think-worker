<?php

namespace think\worker\watcher;

use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use Workerman\Timer;

class Scan implements Driver
{
    protected $finder;

    protected $files = [];
    protected $interval;
    protected $debounce;
    protected $lastTriggerTime = 0;

    public function __construct($directory, $exclude, $name, $interval = 2, $debounce = 0.5)
    {
        $this->interval = $interval;
        $this->debounce = $debounce;

        $this->finder = new Finder();
        $this->finder
            ->files()
            ->name($name)
            ->in($directory)
            ->exclude($exclude);
    }

    protected function findFiles()
    {
        $files = [];
        /** @var SplFileInfo $f */
        foreach ($this->finder as $f) {
            $files[$f->getRealpath()] = $f->getMTime();
        }

        return $files;
    }

    public function watch(callable $callback)
    {
        $this->files = $this->findFiles();

        Timer::add($this->interval, function () use ($callback) {
            $files   = $this->findFiles();
            $changed = false;

            // 检测新增和修改
            foreach ($files as $path => $time) {
                if (empty($this->files[$path]) || $this->files[$path] != $time) {
                    $changed = true;
                    break;
                }
            }

            // 检测删除
            if (!$changed) {
                foreach (array_keys($this->files) as $path) {
                    if (!isset($files[$path])) {
                        $changed = true;
                        break;
                    }
                }
            }

            if ($changed) {
                $now = microtime(true);
                if ($now - $this->lastTriggerTime >= $this->debounce) {
                    $this->lastTriggerTime = $now;
                    call_user_func($callback);
                }
            }

            $this->files = $files;
        });
    }
}
