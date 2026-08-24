<?php

declare(strict_types=1);

namespace tests\feature;

use PHPUnit\Framework\TestCase;
use think\worker\cron\CronExpression;

/**
 * @coversDefaultClass \think\worker\cron\CronExpression
 *
 * @internal
 */
class CronExpressionTest extends TestCase
{
    /*
     *--------------------------------------------------------------------------
     * 表达式解析 & isDue 命中测试
     *--------------------------------------------------------------------------
     */

    /**
     * @return iterable<string, array{0: string, 1: int, 2: bool}>
     */
    public static function isDueCases(): iterable
    {
        // 基准时间：2024-06-15 10:30:15 UTC（周六 = week 6）
        $ts = gmmktime(10, 30, 15, 6, 15, 2024);

        // 5 段（默认补秒=0） vs 6 段
        yield '5段 通用每分钟 -> 秒位必须是0(此时是15) -> false' => ['* * * * *', $ts, false];
        yield '6段 每秒 * * * * * * -> true'                    => ['* * * * * *', $ts, true];
        yield '6段 精确秒15 -> true'                             => ['15 30 10 15 6 *', $ts, true];
        yield '6段 精确秒14 -> false'                            => ['14 30 10 15 6 *', $ts, false];

        // 通配符各字段
        yield 'everyMinute (秒=0) at sec=15 false'              => ['0 * * * * *', $ts, false];
        yield 'everyMinute on sec=0 true'                       => ['0 * * * * *', gmmktime(10, 30, 0, 6, 15, 2024), true];

        // */N 步长
        yield '每 5 秒，15 命中 (*/5)'                           => ['*/5 * * * * *', $ts, true];
        yield '每 7 秒，15%7=1 不命中'                            => ['*/7 * * * * *', $ts, false];
        yield '每 5 分钟，30 分命中 (*/5) 在秒0'                 => ['0 */5 * * * *', gmmktime(10, 30, 0, 6, 15, 2024), true];
        yield '每 7 分钟，30%7=2 不命中 在秒0'                  => ['0 */7 * * * *', gmmktime(10, 30, 0, 6, 15, 2024), false];

        // 列表 a,b,c
        yield '秒列表 10,15,20 -> 15 true'                      => ['10,15,20 * * * * *', $ts, true];
        yield '秒列表 10,20    -> 15 false'                     => ['10,20 * * * * *', $ts, false];

        // 范围 a-b
        yield '秒范围 10-20 -> 15 true'                         => ['10-20 * * * * *', $ts, true];
        yield '秒范围 0-10  -> 15 false'                        => ['0-10 * * * * *', $ts, false];

        // 范围+步长 a-b/c
        yield '范围步长 0-30/5，15 命中'                         => ['0-30/5 * * * * *', $ts, true];
        yield '范围步长 0-30/7，15-0=15%7=1 不命中'              => ['0-30/7 * * * * *', $ts, false];

        // 整点小时 daily
        yield 'hourly at minute 30 在秒0 true'                  => ['0 30 * * * *', gmmktime(10, 30, 0, 6, 15, 2024), true];
        yield 'hourly at minute 29 在秒0 false'                 => ['0 29 * * * *', gmmktime(10, 30, 0, 6, 15, 2024), false];

        // dailyAt 某天
        yield 'daily 10:30 (秒0不行 sec=15)'                    => ['0 30 10 15 6 *', $ts, false];
        yield 'daily 10:30 sec=15 对应表达式'                    => ['15 30 10 15 6 *', $ts, true];

        // 周（周六 = 6）
        yield '仅周六 true'                                      => ['* * * * * 6', $ts, true];
        yield '仅周日 false (周六≠周日)'                         => ['* * * * * 0', $ts, false];
        yield '周一到周五(工作日) false (今天周六)'               => ['* * * * * 1-5', $ts, false];
        yield '周末 0,6 true'                                    => ['* * * * * 0,6', $ts, true];

        // 日 vs 周 OR 语义：15号 或 周六(今天既是15号又是周六都满足)=true
        $sunTs   = gmmktime(10, 30, 0, 6, 16, 2024); // 2024-06-16 = 周日 = week 0
        $mon17Ts = gmmktime(10, 30, 0, 6, 17, 2024); // 2024-06-17 = 周一 = week 1，且日=17
        yield 'day=15 AND week=0 OR语义 都不满足(17号周一)'     => ['0 30 10 15 6 0', $mon17Ts, false];
        yield 'day=16 OR week=0 (周日16号都满足)'               => ['0 30 10 16 6 0', $sunTs,   true];
        yield 'day=1 OR week=0  (周日命中 day不命中 week命中)'  => ['0 30 10 1  6 0', $sunTs,   true];
        yield 'day=16 OR week=5 (只命中day)'                    => ['0 30 10 16 6 5', $sunTs,   true];
    }

    public function testIsDue(): void
    {
        foreach (self::isDueCases() as $name => $case) {
            [$expr, $ts, $expected] = $case;
            $cron = new CronExpression($expr);
            self::assertSame(
                $expected,
                $cron->isDue($ts),
                "case [{$name}] expr={$expr} ts=" . date('Y-m-d H:i:s T', $ts)
            );
        }
    }

    /*
     *--------------------------------------------------------------------------
     * nextRun 计算
     *--------------------------------------------------------------------------
     */

    public function testNextRunEveryMinuteFrom15Sec(): void
    {
        // 2024-06-15 10:30:15 UTC
        $ts   = gmmktime(10, 30, 15, 6, 15, 2024);
        $cron = new CronExpression('0 * * * * *');
        $next = $cron->nextRun($ts);
        self::assertEquals(gmmktime(10, 31, 0, 6, 15, 2024), $next, '应跳到下一分钟00秒');
    }

    public function testNextRunEverySecond(): void
    {
        $ts   = gmmktime(10, 30, 15, 6, 15, 2024);
        $cron = new CronExpression('* * * * * *');
        self::assertSame($ts, $cron->nextRun($ts), '当前秒就应该命中');
    }

    public function testNextRunDailyJumpMonth(): void
    {
        // 6月30日 10:30:00，daily 23:00 -> 当天 23:00
        $jun30 = gmmktime(10, 30, 0, 6, 30, 2024);
        $cron  = new CronExpression('0 0 23 * * *');
        self::assertSame(gmmktime(23, 0, 0, 6, 30, 2024), $cron->nextRun($jun30));

        // 6月30日 23:30:00，daily 23:00 -> 次日（7月1日）23:00
        $jun30Late = gmmktime(23, 30, 0, 6, 30, 2024);
        self::assertSame(gmmktime(23, 0, 0, 7, 1, 2024), $cron->nextRun($jun30Late));
    }

    public function testNextRunCrossYearFebLeap(): void
    {
        // 2024 是闰年，2月29日 12:00:00 找 next 3月1日 12:00
        $ts   = gmmktime(12, 0, 0, 2, 29, 2024);
        $cron = new CronExpression('0 0 12 * * *');
        self::assertSame(gmmktime(12, 0, 0, 3, 1, 2024), $cron->nextRun($ts + 1));
    }

    /*
     *--------------------------------------------------------------------------
     * 时区支持
     *--------------------------------------------------------------------------
     */

    public function testIsDueWithTimezone(): void
    {
        // 2024-06-15 02:30:15 UTC = 北京时间 10:30:15
        $utc  = gmmktime(2, 30, 15, 6, 15, 2024);
        $cron = new CronExpression('15 30 10 15 6 *');
        self::assertFalse($cron->isDue($utc));
        self::assertTrue($cron->isDue($utc, new \DateTimeZone('Asia/Shanghai')));
    }

    /*
     *--------------------------------------------------------------------------
     * 边界：非法表达式
     *--------------------------------------------------------------------------
     */

    public function testInvalidExpressionThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CronExpression('* * * *'); // 只有 4 段
    }

    public function testGetPartByIndex(): void
    {
        $cron = new CronExpression('1 2 3 4 5 6');
        self::assertSame('1', $cron->getPart(CronExpression::SECOND));
        self::assertSame('2', $cron->getPart(CronExpression::MINUTE));
        self::assertSame('3', $cron->getPart(CronExpression::HOUR));
        self::assertSame('4', $cron->getPart(CronExpression::DAY));
        self::assertSame('5', $cron->getPart(CronExpression::MONTH));
        self::assertSame('6', $cron->getPart(CronExpression::WEEK));
    }
}
