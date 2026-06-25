<?php

namespace think\worker\watcher;

use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use Workerman\Timer;

class Scan implements Driver
{
    protected $finder;

    protected $files = [];

    public function __construct($directory, $exclude, $name)
    {
        $this->finder = new Finder();
        $this->finder->files()->name($name);

        if (!empty($exclude)) {
            $this->finder->exclude($exclude);
        }

        if (!empty($directory)) {
            $this->finder->in($directory);
        }
    }

    protected function findFiles()
    {
        $files = [];
        try {
            /** @var SplFileInfo $f */
            foreach ($this->finder as $f) {
                $files[$f->getRealpath()] = $f->getMTime();
            }
        } catch (\Throwable $e) {
            // Finder 在目录列表为空或不可访问时会抛异常
            // 记录错误但不崩溃 watcher worker
            error_log('[think-worker] Scan findFiles error: ' . $e->getMessage());
        }
        return $files;
    }

    public function watch(callable $callback)
    {
        $this->files = $this->findFiles();

        Timer::add(2, function () use ($callback) {
            $files = $this->findFiles();

            $changed = false;

            // 快速路径：文件数量变化直接判定
            if (count($files) !== count($this->files)) {
                $changed = true;
            } else {
                // 检测新增或修改的文件
                foreach ($files as $path => $time) {
                    if (!isset($this->files[$path]) || $this->files[$path] !== $time) {
                        $changed = true;
                        break;
                    }
                }

                // 检测删除的文件
                if (!$changed) {
                    foreach ($this->files as $path => $time) {
                        if (!isset($files[$path])) {
                            $changed = true;
                            break;
                        }
                    }
                }
            }

            if ($changed) {
                $this->files = $files;
                call_user_func($callback);
            }
        });
    }
}