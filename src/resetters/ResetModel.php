<?php

namespace think\worker\resetters;

use think\App;
use think\Model;
use think\worker\contract\ResetterInterface;
use think\worker\Sandbox;
use Throwable;

class ResetModel implements ResetterInterface
{

    public function handle(App $app, Sandbox $sandbox)
    {
        if (class_exists(Model::class)) {
            // Use the cloned app instance directly to avoid holding a Sandbox reference
            Model::setInvoker(function (...$args) use ($app) {
                try {
                    return $app->invoke(...$args);
                } catch (Throwable) {
                    return null;
                }
            });
        }
    }
}