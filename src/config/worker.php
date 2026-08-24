<?php

// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK IT ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006-2018 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: liu21st <liu21st@gmail.com>
// +----------------------------------------------------------------------

use think\worker\websocket\Handler;

return [
    'http'       => [
        'enable'     => true,
        'host'       => '0.0.0.0',
        'port'       => 8080,
        'worker_num' => 4,
        'options'    => [],
    ],
    'websocket'  => [
        'enable'        => false,
        'handler'       => Handler::class,
        'ping_interval' => 25000,
        'ping_timeout'  => 60000,
    ],
    //队列
    'queue'      => [
        'enable'  => false,
        'workers' => [],
    ],
    'hot_update' => [
        'enable'   => env('APP_DEBUG', false),
        'type'     => 'scan',
        'name'     => ['*.php'],
        'include'  => [app_path(), config_path(), root_path('route')],
        'exclude'  => [],
        'interval' => 2,
        'debounce' => 0.5,
    ],
    // 内置定时任务（秒级调度，独立单进程运行）
    'cron'       => [
        'enable'      => false,
        // 继承 think\worker\Task 的任务类列表
        'tasks'       => [
            // \app\task\DemoTask::class,
        ],
        // 自动扫描：命名空间 => 目录路径。命中的 Task 类会自动注册
        'paths'       => [
            // 'app\\task' => app_path('task'),
        ],
        // （可选）缓存驱动名，用于互斥锁/单服务器判断；留空使用默认缓存
        // 推荐使用 Redis 等支持 SETNX 的驱动以获得真正的原子互斥
        'store'       => null,
        // 全局默认：所有任务是否仅在一台服务器上运行（默认 false）
        // 任务级的 onOneServer() / withoutOnOneServer() 始终优先于此配置
        'onOneServer' => false,
        // 全局默认：所有任务失败时的最大尝试次数（含首次，1 = 不重试）
        // 任务级的 tries() 始终优先于此配置
        'tries'       => 1,
    ],
];
