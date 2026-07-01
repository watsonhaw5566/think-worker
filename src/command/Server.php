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

namespace think\worker\command;

use think\console\Command;
use think\console\input\Option;
use think\worker\Manager;

/**
 * Worker Server 命令行类
 */
class Server extends Command
{
    protected $config = [];

    public function configure()
    {
        $this->setName('worker')
            ->addOption('port', 'p', Option::VALUE_OPTIONAL, 'The port to server the application on')
            ->addOption('worker-num', 'wn', Option::VALUE_OPTIONAL, 'The number of http worker processes')
            ->setDescription('Workerman Server for ThinkPHP');
    }

    public function handle(Manager $manager)
    {
        $options = array_filter([
            'http.port'       => $this->input->getOption('port'),
            'http.worker_num' => $this->input->getOption('worker-num'),
        ], function ($value) {
            return $value !== null;
        });

        $manager->setOptions($options);
        $manager->start();
    }

}