<?php

namespace think\worker;

/**
 * @mixin \think\worker\conduit\Driver
 */
class Conduit extends \think\Manager
{

    protected $namespace = "\\think\\worker\\conduit\\driver\\";

    protected function resolveConfig(string $name): mixed
    {
        return $this->app->config->get("worker.conduit.{$name}", []);
    }

    public function getDefaultDriver(): string
    {
        return $this->app->config->get('worker.conduit.type', 'socket');
    }
}