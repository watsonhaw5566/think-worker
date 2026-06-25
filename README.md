ThinkPHP Workerman 扩展
===============

基于 [Workerman 5.x](https://www.workerman.net/doc/workerman/) 为 ThinkPHP 8 提供高性能服务支持。

## 说明
> 由于 Windows 下无法在一个文件里启动多个 Worker，本扩展不支持 Windows 平台。建议使用 Linux / macOS 环境，生产环境推荐使用 supervisor 管理进程。

## 环境要求
- PHP >= 8.2
- ThinkPHP ^8.0
- Workerman ~5.0.0

## 核心特性
- **Http 服务**：直接运行 ThinkPHP 应用，替代传统 FPM 部署
- **Websocket 服务**：支持原生 Handler 与 socket.io 协议，附带房间/推送/事件系统
- **队列服务**：与 think-queue 无缝对接，无需单独起进程
- **Conduit 通道**：支持自定义 Socket 协议与命令/事件分发
- **热更新 Watcher**：开发模式下自动检测文件变更并重载
- **Sandbox 沙盒**：每次请求重置容器、配置、事件、模型等状态
- **进程间通信 IPC**：多 Worker 之间消息推送
- **自定义 Worker**：通过 `worker.init` 事件动态注册 Worker

## 配置
安装后配置文件位于 `config/worker.php`：

```php
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
        'handler'       => \think\worker\websocket\Handler::class,
        'ping_interval' => 25,
        'ping_timeout'  => 60,
    ],
    'queue'      => [
        'enable'  => false,
        'workers' => [],
    ],
    'hot_update' => [
        'enable'  => env('APP_DEBUG', false),
        'name'    => ['*.php'],
        'include' => [app_path(), config_path(), root_path('route')],
        'exclude' => [],
    ],
];
```

## 使用方法

### HttpServer

在命令行启动服务端
~~~
php think worker
~~~

然后通过浏览器访问当前应用

~~~
http://localhost:8080
~~~

生产环境建议使用 supervisor 管理进程

## 访问静态文件
> 建议使用 nginx 反向代理来提供静态文件访问；也可使用路由输出文件内容，示例如下：

```php
Route::get('static/:path', function (string $path) {
    $filename = public_path() . $path;
    return new \think\worker\response\File($filename);
})->pattern(['path' => '.*\.\w+$']);
```

访问路由：`http://localhost/static/文件路径`

## 队列支持

使用方法见 [think-queue](https://github.com/top-think/think-queue)

以下配置代替think-queue里的最后一步:`监听任务并执行`,无需另外起进程执行队列

```php
return [
    // ...
    'queue'      => [
        'enable'  => true,
        //键名是队列名称
        'workers' => [
            //下面参数是不设置时的默认配置
            'default'            => [
                'delay'      => 0,
                'sleep'      => 3,
                'tries'      => 0,
                'timeout'    => 60,
                'worker_num' => 1,
            ],
            //使用@符号后面可指定队列使用驱动
            'default@connection' => [
                //此处可不设置任何参数，使用上面的默认配置
            ],
        ],
    ],
    // ...
];

```

## Websocket

> 使用路由调度的方式，可让不同路径的 Websocket 服务响应不同的事件。

### 配置

在 `config/worker.php` 中设置 `websocket.enable = true` 开启，并可指定 `handler` 类。

```php
'websocket' => [
    'enable'        => true,
    'handler'       => \think\worker\websocket\Handler::class,
    'ping_interval' => 25,  // 秒，0=禁用
    'ping_timeout'  => 60,  // 秒，0=禁用
],
```

- **`ping_interval`**（秒，默认 25）：服务端每隔 25 秒向客户端发送一次 ping 帧
- **`ping_timeout`**（秒，默认 60）：若 60 秒内未收到任何消息则关闭连接

内置 Handler 位于 `think\worker\websocket\Handler`，支持房间（Room）、事件（Event）、推送（Pusher）及 socket.io 协议。

> **socket.io 协议**：若使用 `\think\worker\websocket\socketio\Handler`，同样读取同一配置，下发给客户端时自动转毫秒（socket.io 协议要求毫秒）。

### 路由定义
```php
Route::get('path1', 'controller/action1');
Route::get('path2', 'controller/action2');
```

### 控制器

```php
use think\worker\Websocket;
use think\worker\websocket\Frame;

class Controller
{
    public function action1()
    {
        return (new \think\worker\response\Websocket())
            ->onOpen(...)
            ->onMessage(function (Websocket $websocket, Frame $frame) {
                // ...
            })
            ->onClose(...);
    }

    public function action2()
    {
        return (new \think\worker\response\Websocket())
            ->onOpen(...)
            ->onMessage(function (Websocket $websocket, Frame $frame) {
                // ...
            })
            ->onClose(...);
    }
}
```

### 房间与广播

```php
use think\worker\websocket\Room;

Room::add($clientId, 'room-1');
Room::send('room-1', 'hello room');
```

## 自定义 Worker

监听 `worker.init` 事件，注入 `Manager` 对象后调用 `addWorker` 方法添加自定义 Worker：

~~~php
use think\worker\Manager;
use think\worker\Worker;

// ...

public function handle(Manager $manager)
{
    $worker = $manager->addWorker(function (Worker $worker) {
        // 其他回调或处理
        // 动态添加监听参考 https://www.workerman.net/doc/workerman/worker/listen.html
    });
}

// ...
~~~

## Conduit 通道

除 HTTP 与 Websocket 外，可通过 `Conduit` 驱动实现自定义 Socket 协议服务。
内置 `Socket` 驱动支持命令（Command）/事件（Event）/结果（Result）的结构化消息交互，适用于长连接 RPC、内部通信等场景。

通过 `InteractsWithConduit` 关注点（concern）在 Manager 生命周期内接入自定义协议。

## 热更新 Watcher

`hot_update.enable = true` 时，Watcher 会扫描 `include` 目录下的 `name` 文件（默认 `*.php`），
检测变更后通知主进程重启，便于开发调试。

```php
'hot_update' => [
    'enable'  => env('APP_DEBUG', false),
    'name'    => ['*.php'],
    'include' => [app_path(), config_path(), root_path('route')],
    'exclude' => [],
],
```

## Sandbox 沙盒与 Resetter

每次请求进入时，通过 `Sandbox` 执行注册的 `Resetter`，重置以下状态以避免 Worker 常驻导致的数据污染：

- `ClearInstances`：清空容器实例
- `ResetConfig`：重置配置
- `ResetEvent`：重置事件监听
- `ResetModel`：重置模型数据
- `ResetPaginator`：重置分页状态
- `ResetService`：重置服务

可实现 `think\worker\contract\ResetterInterface` 自定义重置器。