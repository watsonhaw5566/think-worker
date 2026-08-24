ThinkPHP Workerman 扩展
===============

交流群：981069000 [![点击加群](https://pub.idqqimg.com/wpa/images/group.png "点击加群")](https://qm.qq.com/q/A8YNpzrzC8)

## 安装
```
composer require topthink/think-worker
```

## 说明
> 由于windows下无法在一个文件里启动多个worker，所以本扩展不支持windows平台

## 使用方法

### HttpServer

在命令行启动服务端
~~~
php think worker
~~~

然后就可以通过浏览器直接访问当前应用

~~~
http://localhost:8080
~~~

如果需要使用守护进程方式运行，建议使用supervisor来管理进程

## 访问静态文件
> 建议使用nginx来支持静态文件访问，也可使用路由输出文件内容，下面是示例，可参照修改
1. 添加静态文件路由：

```php
Route::get('static/:path', function (string $path) {
    $filename = public_path() . $path;
    return new \think\worker\response\File($filename);
})->pattern(['path' => '.*\.\w+$']);
```

2. 访问路由 `http://localhost/static/文件路径`

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

### 定时任务（Cron / 声明式 Task）

> 内置单进程 Worker 承载定时任务，支持秒级调度；API 与 [think-cron](https://github.com/yunwuxin/think-cron) 的声明式写法一致，并在其基础上扩展了秒级能力。

#### 1. 开启 Cron Worker

在 `config/worker.php` 中打开：

```php
return [
    // ...
    'cron' => [
        'enable'  => true,                 // 开启定时任务进程（单进程 worker_num=1）
        'tasks'   => [],                   // 方式一：直接列出 Task 类名数组
        // 'path'    => app_path('task'), // 方式二：自动扫描该目录下的所有 Task 子类
        // 'cache'   => null,              // 使用哪个缓存驱动实现 onOneServer / withoutOverlapping 锁
    ],
    // ...
];
```

启动后会在独立进程中每秒触发一次调度器：
```
php think worker
```

#### 2. 编写 Task

继承 `think\worker\Task` 抽象类，在 `configure()` 中用 Fluent API 描述执行周期，在 `execute()` 中写业务逻辑：

```php
<?php

namespace app\cron;

use think\worker\Task;

class CleanupExpiredTokens extends Task
{
    protected function configure(): void
    {
        $this->everyFiveMinutes()             // 每 5 分钟
             ->name('清理过期令牌')           // 任务名（默认类名）
             ->withoutOverlapping(60)         // 防重叠执行（60 分钟互斥锁）
             ->onOneServer()                  // 分布式下只在一台服务器执行
             ->tries(3, 30)                   // 失败最多重试 3 次，间隔 30 秒
             ->between('08:00', '22:00')      // 只在工作时间内执行
             ->timezone('Asia/Shanghai');
    }

    protected function execute(): void
    {
        // 实际业务：如过期数据清理、报表生成、数据同步等
        \think\facade\Db::name('token')
            ->where('expire_time', '<', time())
            ->delete();
    }
}
```

将该类放入 `config/worker.php` 的 `cron.tasks` 数组或放入扫描目录即可生效。

#### 3. 常用调度表达式

分钟级（与 think-cron 完全一致）：

| 方法 | 等价 Cron |
|------|----------|
| `everyMinute()` | `0 * * * * *` |
| `everyFiveMinutes()` / `everyMinutes(7)` | `0 */5 * * * *` / `0 */7 * * * *` |
| `hourly()` / `hourlyAt(17)` | `0 0 * * * *` / `0 17 * * * *` |
| `daily()` / `dailyAt('13:00')` | `0 0 0 * * *` / `0 0 13 * * *` |
| `weekly()` / `weeklyOn(5, '9:30')` | `0 0 0 * * 1` / `0 30 9 * * 5` |
| `monthly()` / `monthlyOn(15, '22:15')` | `0 0 0 1 * *` / `0 15 22 15 * *` |
| `quarterly()` / `yearly()` | `0 0 0 1 1,4,7,10 *` / `0 0 0 1 1 *` |
| `weekdays()` / `weekends()` / `mondays()` / `days(1,3,5)` | 周字段约束 |
| `expression('0 30 2 * * 0')` | 自定义 5 段表达式，自动补秒位 0 |

秒级扩展（本库特有）：

| 方法 | 等价 Cron |
|------|----------|
| `everySecond()` | `* * * * * *` |
| `everySeconds(15)` | `*/15 * * * * *` |
| `everySecondAt([0, 20, 40])` | `0,20,40 * * * * *` |
| `expression('*/10 * * * * *')` | 自定义 6 段表达式 |

#### 4. 高级特性

- **onOneServer()**：基于缓存的分布式锁，保证任意时刻只有一个节点执行（依赖 `cron.cache` 指定的共享缓存驱动，如 Redis）。
- **withoutOverlapping($expireMinutes = 1440)**：即使调度频率高于任务耗时，也不会并发执行同一个任务。
- **tries($count, $retryDelaySec = 0)**：`execute()` 抛出异常时自动重试。
- **between($start, $end)** / **unlessBetween($start, $end)**：限定只在（或不在）某时间段内执行。
- **when(Closure)** / **skip(Closure)**：动态条件决定是否执行。
- **disable()** / **enable()**：临时启用/禁用任务。

配合事件监听可实现任务日志、告警等：
```php
// 事件类：
//   think\worker\event\TaskProcessed  — 执行成功
//   think\worker\event\TaskSkipped    — 因条件/锁被跳过
//   think\worker\event\TaskFailed     — 最终执行失败（含重试耗尽）
\think\facade\Event::listen(\think\worker\event\TaskFailed::class, function ($event) {
    // $event->task / $event->exception / $event->runtimeMs
});
```

### websocket

> 使用路由调度的方式，可以让不同路径的websocket服务响应不同的事件

#### 配置

```
worker.websocket = true 时开启
```

#### 路由定义
```php
Route::get('path1','controller/action1');
Route::get('path2','controller/action2');
```

#### 控制器

```php
use \think\worker\Websocket;
use \think\worker\websocket\Frame;

class Controller {

    public function action1(){
    
        return (new \think\worker\response\Websocket())
            ->onOpen(...)
            ->onMessage(function(Websocket $websocket, Frame $frame){ 
                ...
            })
            ->onClose(...);
    }
    
    public function action2(){
    
        return (new \think\worker\response\Websocket())
            ->onOpen(...)
            ->onMessage(function(Websocket $websocket, Frame $frame){
               ...
            })
            ->onClose(...);
    }
}
```


## 定时任务（Cron）

think-worker 内置声明式定时任务调度器，API 与 think-cron 保持一致，并扩展了**秒级**调度能力。由独立的单进程 Worker 承载，每秒 tick 一次，通过 ThinkPHP 容器和事件系统与业务解耦。

### 开启

在 `config/worker.php` 中启用：

```php
return [
    // ...
    'cron' => [
        'enable'       => true,            // 启用定时任务 Worker（单进程）
        'onOneServer'  => false,           // 全局默认：是否分布式下只允许一台机器执行
        'tries'        => 1,               // 全局默认：失败重试次数
        'store'        => null,            // 锁使用的缓存 store，默认使用 cache.default
        'tasks'        => [                // 方式一：直接列出任务类
            \app\task\SendReport::class,
            \app\task\CleanTempFile::class,
        ],
        'paths'        => [                // 方式二：扫描目录（文件内继承 Task 的类会被自动注册）
            app_path() . 'task',
        ],
    ],
];
```

### 编写任务类

继承 `think\worker\Task` 抽象类，在 `configure()` 中声明执行周期，在 `execute()` 中编写业务逻辑：

```php
<?php

namespace app\task;

use think\worker\Task;

class SendReport extends Task
{
    protected function configure(): void
    {
        // 每天早上 9:00 执行
        $this->dailyAt('09:00')
             ->name('morning-report')                // 可选：自定义任务名（用于锁 & 日志）
             ->timezone('Asia/Shanghai')             // 可选：指定时区
             ->withoutOverlapping(300)               // 可选：执行期间 300s 互斥锁
             ->onOneServer();                        // 可选：分布式仅一台执行
    }

    protected function execute(): void
    {
        // 业务代码：可正常注入服务 / Db / Cache 等 ThinkPHP 容器服务
        $data = [/* ... */];
        mail('ops@example.com', 'Daily Report', json_encode($data));
    }
}
```

### Fluent 周期声明 API

**分钟级调度（与 think-cron 对齐）：**

| 方法 | 说明 | 等价 cron |
|------|------|-----------|
| `everyMinute()` | 每分钟整点 | `0 * * * * *` |
| `everyFiveMinutes()` / `everyTenMinutes()` / `everyThirtyMinutes()` | 每 N 分钟 | `0 */5 * * * *` |
| `everyMinutes(int $n)` | 自定义每 N 分钟 | `0 */$n * * * *` |
| `hourly()` / `hourlyAt(int $minute)` | 每小时 / 每小时第 N 分 | `0 0 * * * *` / `$minute 0 * * * *` |
| `daily()` / `dailyAt(string $time)` | 每天 / 每天指定时间 | `0 0 0 * * *` / `i H * * * *` |
| `at(string $time)` | 别名 dailyAt | 同上 |
| `twiceDaily(int $first = 1, int $second = 13)` | 每天两次 | `0 1,13 * * * *` |
| `weekly()` / `weeklyOn(int $day, string $time = '0:0')` | 每周 / 每周某天 | 周 1 00:00 |
| `monthly()` / `monthlyOn(int $day, string $time)` | 每月 / 每月某天指定时间 | 1 号 00:00 |
| `twiceMonthly(int $first = 1, int $second = 16)` | 每月两次 | 1 & 16 号 |
| `quarterly()` / `yearly()` | 每季度 / 每年 | — |

**工作日限定：**

| 方法 | 说明 |
|------|------|
| `weekdays()` | 周一至周五 |
| `weekends()` | 周六 & 周日 |
| `mondays()` / `tuesdays()` / `wednesdays()` / `thursdays()` / `fridays()` / `saturdays()` / `sundays()` | 指定星期 |
| `days(int ...$days)` | 自定义星期列表（0=周日, 1=周一 … 6=周六）|

**秒级调度（扩展）：**

| 方法 | 说明 | 等价 cron |
|------|------|-----------|
| `everySecond()` | 每秒都执行 | `* * * * * *` |
| `everySeconds(int $n)` | 每 N 秒 | `*/$n * * * * *` |
| `everySecondAt(array $offsets)` | 指定秒位置，如 `[0, 20, 40]` | `0,20,40 * * * * *` |

**原生表达式：**

```php
$this->expression('*/5 * * * *');        // 5 段：分钟级，自动补秒位为 0
$this->expression('*/3 * * * * *');       // 6 段：秒级，原样保留
```

### 高级特性

```php
protected function configure(): void
{
    $this->everyFiveMinutes()
         // 1) 执行窗口
         ->between('09:00', '18:00')               // 仅 09:00-18:00 允许执行
         ->unlessBetween('12:00', '13:30')          // 但 12:00-13:30（午休）禁止

         // 2) 条件控制（支持链式多次叠加，全部通过才执行）
         ->when(fn() => config('app.report_enabled'))
         ->skip(fn() => holiday(date('Y-m-d')))     // skip 任一返回 true 即跳过

         // 3) 失败重试：失败后最多重试 3 次，每次间隔 10s
         ->tries(3, retryDelaySec: 10)

         // 4) 并发 & 分布式控制
         ->withoutOverlapping(600)                  // 执行期间互斥，10 分钟兜底
         ->onOneServer();                           // 多节点部署时只在一台机器触发
}
```

### 事件监听

调度器会触发以下事件，可在全局事件监听中订阅以记录日志 / 告警：

| 事件类 | 触发时机 | 关键属性 |
|--------|---------|---------|
| `think\worker\event\TaskProcessed` | 任务成功完成 | `$task` |
| `think\worker\event\TaskSkipped` | 任务因条件 / 锁 / 抢占未执行 | `$task`、`$reason`（`between` / `callback` / `single_server` / `overlapping`）|
| `think\worker\event\TaskFailed` | 任务经过全部重试仍失败 | `$task`、`$exception`、`$attempts` |

示例（在 `event.php` 中订阅）：

```php
return [
    'listen' => [
        \think\worker\event\TaskFailed::class => [
            function (\think\worker\event\TaskFailed $event) {
                $name = $event->task->getName();
                logs('cron')->error("任务[{$name}]失败：{$event->exception->getMessage()}");
            },
        ],
    ],
];
```


## 自定义worker
监听`worker.init`事件 注入`Manager`对象，调用addWorker方法添加
~~~php
use think\worker\Manager;
use \think\worker\Worker;

//...

public function handle(Manager $manager){
   $worker = $manager->addWorker(function(Worker $worker){
        //..其他回调或处理
        //动态添加监听可参考 https://www.workerman.net/doc/workerman/worker/listen.html
    });
}

//...
~~~
