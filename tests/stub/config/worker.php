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

return [
    'http'       => [
        'enable'     => env('HTTP_ENABLE', true),
        'host'       => '0.0.0.0',
        'port'       => 8080,
        'worker_num' => 1,
        'options'    => [],
    ],
    //队列
    'queue'      => [
        'enable'  => env('QUEUE_ENABLE', false),
        'workers' => [
            'default' => [],
        ],
    ],
    'hot_update' => [
        'enable'  => env('APP_DEBUG', false),
        'name'    => ['*.php'],
        'include' => [app_path(), config_path(), root_path('route')],
        'exclude' => [],
    ],
];
