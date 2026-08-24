<?php

declare(strict_types=1);

namespace tests\feature;

use PHPUnit\Framework\TestCase;
use think\App;
use think\worker\Task;
use Closure;

/**
 * @coversDefaultClass \think\worker\Task
 *
 * @internal
 */
class TaskTest extends TestCase
{
    private function makeApp(): App
    {
        $mock = $this->createMock(App::class);

        // Task::__construct 不调用 App 的任何方法，仅存引用
        return $mock;
    }

    /**
     * 创建 Task 实例（通过 PHPUnit mock abstract Task，避免重写 final __construct）
     *
     * @param Closure(Task):mixed $configurator 通过 Fluent API 配置任务（允许链式返回 $this）
     */
    private function makeTask(Closure $configurator): Task
    {
        $app = $this->makeApp();

        // PHPUnit 12：使用 getMockBuilder() 对 abstract 方法生成空 stub
        $builder = $this->getMockBuilder(Task::class)
            ->setConstructorArgs([$app])
            ->onlyMethods(['configure', 'execute']);
        /** @var Task&\PHPUnit\Framework\MockObject\MockObject $task */
        $task = $builder->getMock();

        // 手动通过 Fluent API 执行用户配置（final __construct 已调用了空的 configure() stub）
        $configurator($task);

        return $task;
    }

    /*
     *--------------------------------------------------------------------------
     * Fluent 表达式生成验证
     *--------------------------------------------------------------------------
     */

    /**
     * @return iterable<string,array{0:Closure,1:string}>
     */
    public static function expressionCases(): iterable
    {
        yield 'everyMinute' => [
            fn (Task $t) => $t->everyMinute(),
            '0 * * * * *',
        ];
        yield 'everyFiveMinutes' => [
            fn (Task $t) => $t->everyFiveMinutes(),
            '0 */5 * * * *',
        ];
        yield 'everyTenMinutes' => [
            fn (Task $t) => $t->everyTenMinutes(),
            '0 */10 * * * *',
        ];
        yield 'everyThirtyMinutes' => [
            fn (Task $t) => $t->everyThirtyMinutes(),
            '0 */30 * * * *',
        ];
        yield 'everyMinutes(7)' => [
            fn (Task $t) => $t->everyMinutes(7),
            '0 */7 * * * *',
        ];
        yield 'hourly 默认整点 0分' => [
            fn (Task $t) => $t->hourly(),
            '0 0 * * * *',
        ];
        yield 'hourlyAt(17)' => [
            fn (Task $t) => $t->hourlyAt(17),
            '0 17 * * * *',
        ];
        yield 'daily 默认 00:00' => [
            fn (Task $t) => $t->daily(),
            '0 0 0 * * *',
        ];
        yield 'dailyAt 13:00' => [
            fn (Task $t) => $t->dailyAt('13:00'),
            '0 0 13 * * *',
        ];
        yield 'at 02:30' => [
            fn (Task $t) => $t->at('02:30'),
            '0 30 2 * * *',
        ];
        yield 'twiceDaily 默认 1时与13时' => [
            fn (Task $t) => $t->twiceDaily(),
            '0 0 1,13 * * *',
        ];
        yield 'weekly 默认 周一00:00' => [
            fn (Task $t) => $t->weekly(),
            '0 0 0 * * 1',
        ];
        yield 'weeklyOn(5, 9:30)' => [
            fn (Task $t) => $t->weeklyOn(5, '9:30'),
            '0 30 9 * * 5',
        ];
        yield 'monthly 默认1号00:00' => [
            fn (Task $t) => $t->monthly(),
            '0 0 0 1 * *',
        ];
        yield 'monthlyOn(15, 22:15)' => [
            fn (Task $t) => $t->monthlyOn(15, '22:15'),
            '0 15 22 15 * *',
        ];
        yield 'twiceMonthly 默认1号与16号' => [
            fn (Task $t) => $t->twiceMonthly(),
            '0 0 0 1,16 * *',
        ];
        yield 'quarterly' => [
            fn (Task $t) => $t->quarterly(),
            '0 0 0 1 1,4,7,10 *',
        ];
        yield 'yearly' => [
            fn (Task $t) => $t->yearly(),
            '0 0 0 1 1 *',
        ];
        yield 'weekdays -> 周1-5' => [
            fn (Task $t) => $t->dailyAt('8:00')->weekdays(),
            '0 0 8 * * 1-5',
        ];
        yield 'weekends -> 周0,6' => [
            fn (Task $t) => $t->dailyAt('10:00')->weekends(),
            '0 0 10 * * 0,6',
        ];
        yield 'mondays' => [
            fn (Task $t) => $t->hourly()->mondays(),
            '0 0 * * * 1',
        ];
        yield 'days(1,3,5)' => [
            fn (Task $t) => $t->hourly()->days(1, 3, 5),
            '0 0 * * * 1,3,5',
        ];
        yield 'days([0,6]) 周末' => [
            fn (Task $t) => $t->hourly()->days([0, 6]),
            '0 0 * * * 0,6',
        ];

        // 秒级扩展
        yield 'everySecond' => [
            fn (Task $t) => $t->everySecond(),
            '* * * * * *',
        ];
        yield 'everySeconds(15)' => [
            fn (Task $t) => $t->everySeconds(15),
            '*/15 * * * * *',
        ];
        yield 'everySecondAt([0,20,40])' => [
            fn (Task $t) => $t->everySecondAt([0, 20, 40]),
            '0,20,40 * * * * *',
        ];

        // expression 自定义：5段自动补秒0
        yield 'expression 5段补秒0' => [
            fn (Task $t) => $t->expression('30 2 * * 0'),
            '0 30 2 * * 0',
        ];
        yield 'expression 6段原样保留' => [
            fn (Task $t) => $t->expression('10 */3 1-5 1 1-6 6'),
            '10 */3 1-5 1 1-6 6',
        ];
    }

    public function testExpressionGeneratedCorrectly(): void
    {
        foreach (self::expressionCases() as $name => [$cfg, $expected]) {
            $task = $this->makeTask($cfg);
            self::assertSame($expected, $task->getExpression(), "case [{$name}]");
        }
    }

    /*
     *--------------------------------------------------------------------------
     * 元信息字段
     *--------------------------------------------------------------------------
     */

    /**
     */
    public function testMetaFieldsDefaultAndSetters(): void
    {
        $task = $this->makeTask(function (Task $t) {
            $t->everyMinute()
              ->name('我的任务')
              ->timezone('Asia/Shanghai')
              ->onOneServer()
              ->withoutOverlapping(60)
              ->tries(3, 30)
              ->disable();
        });

        self::assertSame('我的任务', $task->getName());
        self::assertSame('Asia/Shanghai', $task->getTimezone());
        self::assertTrue($task->getOnOneServer());
        self::assertSame(3600, $task->getLockExpireSec()); // 60min * 60s
        self::assertSame(3, $task->getTries());
        self::assertSame(30, $task->getRetryDelaySec());
        self::assertFalse($task->isEnabled());

        // enable() 重新打开
        $task2 = $this->makeTask(fn (Task $t) => $t->everyMinute()->disable()->enable());
        self::assertTrue($task2->isEnabled());

        // withoutOnOneServer 显式关闭
        $task3 = $this->makeTask(fn (Task $t) => $t->everyMinute()->withoutOnOneServer());
        self::assertFalse($task3->getOnOneServer());

        // 默认 onOneServer 为 null（未设置）
        $task4 = $this->makeTask(fn (Task $t) => $t->everyMinute());
        self::assertNull($task4->getOnOneServer());
    }

    /**
     */
    public function testDefaultNameIsClass(): void
    {
        // 未调用 name()，getName 应返回类名（匿名类名非空）
        $task = $this->makeTask(fn (Task $t) => $t->everyMinute());
        self::assertNotEmpty($task->getName());
    }

    /**
     */
    public function testTriesOneMeansNoRetryDefault(): void
    {
        $task = $this->makeTask(fn (Task $t) => $t->everyMinute());
        self::assertSame(1, $task->getTries(), '默认不重试');
        self::assertSame(0, $task->getRetryDelaySec());
    }

    /*
     *--------------------------------------------------------------------------
     * between / unlessBetween / when / skip 条件
     *--------------------------------------------------------------------------
     */

    /**
     */
    public function testBetweenAndUnlessBetweenRulesCollected(): void
    {
        $task = $this->makeTask(function (Task $t) {
            $t->everyMinute()
              ->between('09:00', '18:00')
              ->unlessBetween('12:00', '13:00')
              ->between('23:00', '02:00');
        });
        $rules = $task->getBetweenRules();
        self::assertCount(3, $rules);
        self::assertSame(['09:00', '18:00', true], $rules[0]);
        self::assertSame(['12:00', '13:00', false], $rules[1]);
        self::assertSame(['23:00', '02:00', true], $rules[2]);
    }

    /**
     */
    public function testWhenSkipCallbacksCollectedAsClosures(): void
    {
        $whenA = function () {
            return true;
        };
        $whenB = function () {
            return true;
        };
        $skipA = function () {
            return false;
        };

        $task = $this->makeTask(function (Task $t) use ($whenA, $whenB, $skipA) {
            $t->everyMinute()->when($whenA)->skip($skipA)->when($whenB);
        });

        self::assertCount(2, $task->getWhenCallbacks());
        self::assertCount(1, $task->getSkipCallbacks());
        self::assertSame($whenA, $task->getWhenCallbacks()[0]);
        self::assertSame($whenB, $task->getWhenCallbacks()[1]);
        self::assertSame($skipA, $task->getSkipCallbacks()[0]);
    }
}
