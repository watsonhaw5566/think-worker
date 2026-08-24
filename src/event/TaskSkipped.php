<?php

declare(strict_types=1);

namespace think\worker\event;

use think\worker\Task;

class TaskSkipped
{
    public function __construct(
        public readonly Task   $task,
        public readonly string $reason,
    ) {
    }

    public function getName(): string
    {
        return $this->task->getName();
    }
}
