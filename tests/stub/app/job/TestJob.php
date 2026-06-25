<?php

namespace app\job;

use think\queue\Job;

class TestJob
{
    public function fire(Job $job, $data)
    {
        $path = $data['path'] ?? (sys_get_temp_dir() . '/think_worker_queue_test.log');

        file_put_contents(
            $path,
            json_encode([
                'time' => microtime(true),
                'data' => $data,
                'pid'  => getmypid(),
            ], JSON_UNESCAPED_SLASHES) . "\n",
            FILE_APPEND | LOCK_EX
        );

        $job->delete();
    }
}