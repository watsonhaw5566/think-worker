<?php

namespace think\worker\concerns;

use think\App;
use think\exception\Handle;
use Throwable;

trait WithContainer
{

    /**
     * @var App
     */
    protected $container;

    /**
     * 命令行覆盖配置
     * @var array
     */
    protected $options = [];

    /**
     * Manager constructor.
     * @param App $container
     */
    public function __construct(App $container)
    {
        $this->container = $container;
    }

    protected function getContainer()
    {
        return $this->container;
    }

    /**
     * 设置命令行覆盖配置
     * @param array $options
     */
    public function setOptions(array $options): void
    {
        foreach ($options as $key => $value) {
            if ($value !== null) {
                $this->options[$key] = $value;
            }
        }
    }

    /**
     * 获取配置
     * @param string $name
     * @param mixed $default
     * @return mixed
     */
    public function getConfig(string $name, $default = null)
    {
        if (array_key_exists($name, $this->options)) {
            return $this->options[$name];
        }

        return $this->container->config->get("worker.{$name}", $default);
    }

    /**
     * 触发事件
     * @param string $event
     * @param mixed $params
     */
    public function triggerEvent(string $event, $params = null): void
    {
        $this->container->event->trigger("worker.{$event}", $params);
    }

    /**
     * 监听事件
     * @param string $event
     * @param        $listener
     * @param bool $first
     */
    public function onEvent(string $event, $listener, bool $first = false): void
    {
        $this->container->event->listen("worker.{$event}", $listener, $first);
    }

    /**
     * Log server error.
     *
     * @param Throwable $e
     */
    public function logServerError(Throwable $e)
    {
        /** @var Handle $handle */
        $handle = $this->container->make(Handle::class);

        $handle->report($e);
    }
}