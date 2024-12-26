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
    return new \think\swoole\response\File($filename);
})->pattern(['path' => '.*\.\w+$']);
```

2. 访问路由 `http://localhost/static/文件路径`

## 自定义worker
监听`worker.init`事件 注入`Manager`对象，调用addWorker方法添加
~~~php
use think\worker\Manager;
use Workerman\Worker;

//...

public function handle(Manager $manager){
    $manager->addWorker(function(){
        $worker = new Worker();

        $worker->onWorkerStart = function () {
            //...一些处理
        };
        
        //..其他回调或处理

        return $worker;
    });
}

//...
~~~
