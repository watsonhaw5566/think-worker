<?php

namespace think\worker;

use Closure;
use InvalidArgumentException;
use ReflectionObject;
use RuntimeException;
use think\Config;
use think\Container;
use think\Event;
use think\exception\Handle;
use think\worker\App;
use think\worker\concerns\ModifyProperty;
use think\worker\contract\ResetterInterface;
use think\worker\resetters\ClearInstances;
use think\worker\resetters\ResetConfig;
use think\worker\resetters\ResetEvent;
use think\worker\resetters\ResetModel;
use think\worker\resetters\ResetPaginator;
use think\worker\resetters\ResetService;
use Throwable;
use WeakMap;

class Sandbox
{
    use ModifyProperty;

    protected WeakMap $snapshots;

    /** @var \SplStack<App> Stack of active snapshots for reentrancy support */
    protected \SplStack $snapshotStack;

    protected App $app;

    protected Config $config;

    protected Event $event;

    /** @var ResetterInterface[] */
    protected array $resetters = [];
    protected array $services  = [];

    public function __construct(App $app)
    {
        $this->app           = $app;
        $this->snapshots     = new WeakMap();
        $this->snapshotStack = new \SplStack();
        $this->initialize();
    }

    protected function initialize(): void
    {
        Container::setInstance(function () {
            return $this->getSnapshot();
        });

        $this->setInitialConfig();
        $this->setInitialServices();
        $this->setInitialEvent();
        $this->setInitialResetters();
    }

    public function run(Closure $callable, ?object $key = null): void
    {
        $snapshot = $this->createApp($key);
        $this->snapshotStack->push($snapshot);

        $caughtException = null;
        try {
            $snapshot->invoke($callable, [$this]);
        } catch (Throwable $e) {
            $caughtException = $e;
        } finally {
            $this->snapshotStack->pop();

            if ($caughtException !== null) {
                try {
                    $snapshot->make(Handle::class)->report($caughtException);
                } catch (Throwable) {
                    // Ignore errors during error reporting to prevent cascading failures
                }
            }

            if (empty($key)) {
                $snapshot->clearInstances();
            }

            $this->setInstance($this->app);
        }
    }

    protected function createApp(?object $key = null): App
    {
        if (!empty($key)) {
            if (isset($this->snapshots[$key])) {
                return $this->snapshots[$key]->app;
            }
        }

        $app = clone $this->app;
        $this->setInstance($app);
        $this->resetApp($app);

        if (!empty($key)) {
            $this->snapshots[$key] = new class($app) {
                public function __construct(public App $app)
                {
                }

                public function __destruct()
                {
                    $this->app->clearInstances();
                }
            };
        }

        return $app;
    }

    protected function resetApp(App $app): void
    {
        foreach ($this->resetters as $resetter) {
            $resetter->handle($app, $this);
        }
    }

    protected function setInstance(App $app): void
    {
        $app->instance('app', $app);
        $app->instance(Container::class, $app);

        $reflectObject   = new ReflectionObject($app);
        $reflectProperty = $reflectObject->getProperty('services');
        $services        = $reflectProperty->getValue($app);

        foreach ($services as $service) {
            $this->modifyProperty($service, $app);
        }
    }

    /**
     * Set initial config.
     */
    protected function setInitialConfig(): void
    {
        $this->config = clone $this->app->config;
    }

    protected function setInitialEvent(): void
    {
        $this->event = clone $this->app->event;
    }

    protected function setInitialServices(): void
    {
        $services = $this->config->get('worker.services', []);

        foreach ($services as $service) {
            if (class_exists($service) && !in_array($service, $this->services)) {
                $serviceObj               = new $service($this->app);
                $this->services[$service] = $serviceObj;
            }
        }
    }

    /**
     * Initialize resetters.
     */
    protected function setInitialResetters(): void
    {
        $resetters = [
            ClearInstances::class,
            ResetConfig::class,
            ResetEvent::class,
            ResetService::class,
            ResetModel::class,
            ResetPaginator::class,
        ];

        $resetters = array_merge($resetters, $this->config->get('worker.resetters', []));

        foreach ($resetters as $resetter) {
            $resetterClass = $this->app->make($resetter);
            if (!$resetterClass instanceof ResetterInterface) {
                throw new RuntimeException("{$resetter} must implement " . ResetterInterface::class);
            }
            $this->resetters[$resetter] = $resetterClass;
        }
    }

    public function getSnapshot(): App
    {
        if (!$this->snapshotStack->isEmpty()) {
            return $this->snapshotStack->top();
        }

        throw new InvalidArgumentException('The app object has not been initialized');
    }

    /**
     * Get config snapshot.
     */
    public function getConfig(): Config
    {
        return $this->config;
    }

    public function getEvent(): Event
    {
        return $this->event;
    }

    public function getServices(): array
    {
        return $this->services;
    }

}