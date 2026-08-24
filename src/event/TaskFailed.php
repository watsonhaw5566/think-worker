<?php

declare(strict_types=1);

namespace think\worker\event;

use think\worker\Task;
use Throwable;

class TaskFailed
{
    public function __construct(
        public readonly Task      $task,
        public readonly Throwable $exception,
        public readonly int       $attempts,
    ) {
    }

    public function getName(): string
    {
        return $this->task->getName();
    }
}
