<?php

declare(strict_types=1);

namespace think\worker\event;

use think\worker\Task;

class TaskProcessed
{
    public function __construct(public readonly Task $task)
    {
    }

    public function getName(): string
    {
        return $this->task->getName();
    }
}
