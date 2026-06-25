<?php

namespace think\worker;

class App extends \think\App
{
    protected bool $inConsole = true;

    public function setInConsole(bool $inConsole = true): void
    {
        $this->inConsole = $inConsole;
    }

    public function runningInConsole(): bool
    {
        return $this->inConsole;
    }

    public function clearInstances(): void
    {
        $this->instances = [];
    }
}