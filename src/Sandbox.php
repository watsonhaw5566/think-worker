<?php

namespace think\worker;

use Closure;
use InvalidArgumentException;
use ReflectionObject;
use RuntimeException;
use think\App;
use think\Config;
use think\Container;
use think\Event;
use think\exception\Handle;
use think\worker\App as WorkerApp;
use think\worker\concerns\ModifyProperty;
use think\worker\contract\ResetterInterface;
use think\worker\resetters\ClearInstances;
use think\worker\resetters\ResetConfig;
use think\worker\resetters\ResetEvent;
use think\worker\resetters\ResetModel;
use think\worker\resetters\ResetPaginator;
use think\worker\resetters\ResetService;
use Throwable;

class Sandbox
{
    use ModifyProperty;

    /** @var WorkerApp|null */
    protected $snapshot;

    /** @var Container */
    protected $app;

    /** @var Config */
    protected $config;

    /** @var Event */
    protected $event;

    /** @var ResetterInterface[] */
    protected $resetters = [];
    protected $services  = [];

    public function __construct(Container $app)
    {
        $this->setBaseApp($app);
        $this->initialize();
    }

    public function setBaseApp(Container $app)
    {
        $this->app = $app;

        return $this;
    }

    public function getBaseApp()
    {
        return $this->app;
    }

    protected function initialize()
    {
        Container::setInstance(function () {
            return $this->getApplication();
        });

        $this->setInitialConfig();
        $this->setInitialServices();
        $this->setInitialEvent();
        $this->setInitialResetters();

        return $this;
    }

    public function run(Closure $callable)
    {
        $app = $this->init();
        try {
            $app->invoke($callable, [$this]);
        } catch (Throwable $e) {
            $app->make(Handle::class)->report($e);
        } finally {
            $this->clear();
        }
    }

    public function init()
    {
        $app = clone $this->getBaseApp();
        $this->setInstance($app);
        $this->resetApp($app);

        $this->snapshot = $app;
        return $app;
    }

    public function clear()
    {
        if ($this->snapshot) {
            $this->snapshot->clearInstances();
            $this->snapshot = null;
        }

        $this->setInstance($this->getBaseApp());
    }

    public function getApplication()
    {
        $snapshot = $this->snapshot;
        if ($snapshot instanceof Container) {
            return $snapshot;
        }

        throw new InvalidArgumentException('The app object has not been initialized');
    }

    public function setInstance(Container $app)
    {
        $app->instance('app', $app);
        $app->instance(Container::class, $app);

        $reflectObject   = new ReflectionObject($app);
        $reflectProperty = $reflectObject->getProperty('services');
        $reflectProperty->setAccessible(true);
        $services = $reflectProperty->getValue($app);

        foreach ($services as $service) {
            $this->modifyProperty($service, $app);
        }
    }

    /**
     * Set initial config.
     */
    protected function setInitialConfig()
    {
        $this->config = clone $this->getBaseApp()->config;
    }

    protected function setInitialEvent()
    {
        $this->event = clone $this->getBaseApp()->event;
    }

    /**
     * Get config snapshot.
     */
    public function getConfig()
    {
        return $this->config;
    }

    public function getEvent()
    {
        return $this->event;
    }

    public function getServices()
    {
        return $this->services;
    }

    protected function setInitialServices()
    {
        $app = $this->getBaseApp();

        $services = $this->config->get('swoole.services', []);

        foreach ($services as $service) {
            if (class_exists($service) && !in_array($service, $this->services)) {
                $serviceObj               = new $service($app);
                $this->services[$service] = $serviceObj;
            }
        }
    }

    /**
     * Initialize resetters.
     */
    protected function setInitialResetters()
    {
        $app = $this->getBaseApp();

        $resetters = [
            ClearInstances::class,
            ResetConfig::class,
            ResetEvent::class,
            ResetService::class,
            ResetModel::class,
            ResetPaginator::class,
        ];

        $resetters = array_merge($resetters, $this->config->get('swoole.resetters', []));

        foreach ($resetters as $resetter) {
            $resetterClass = $app->make($resetter);
            if (!$resetterClass instanceof ResetterInterface) {
                throw new RuntimeException("{$resetter} must implement " . ResetterInterface::class);
            }
            $this->resetters[$resetter] = $resetterClass;
        }
    }

    /**
     * Reset Application.
     *
     * @param App $app
     */
    protected function resetApp(App $app)
    {
        foreach ($this->resetters as $resetter) {
            $resetter->handle($app, $this);
        }
    }

}
