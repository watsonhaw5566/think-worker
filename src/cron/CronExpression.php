<?php

declare(strict_types=1);

namespace think\worker\cron;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;

/**
 * 6 段 Cron 表达式解析器（秒级粒度）
 *
 * 字段顺序：
 *   0  1  2  3  4  5
 *   秒 分 时 日 月 周
 *
 * 语法：
 *   *         任意
 *   N         精确匹配
 *   N,M,K     列表
 *   N-M       范围
 *   N-M/S     范围 + 步长
 *   *\/S      任意 + 步长
 *
 * 日与周的判定：两者均非 * 时使用「OR」语义（任一匹配即命中，符合标准 cron 行为）。
 */
class CronExpression
{
    public const SECOND = 0;
    public const MINUTE = 1;
    public const HOUR   = 2;
    public const DAY    = 3;
    public const MONTH  = 4;
    public const WEEK   = 5;

    /** @var array<int,string> */
    private array $parts;

    /** @var array<int,array{0:int,1:int}> */
    private static array $ranges = [
        self::SECOND => [0, 59],
        self::MINUTE => [0, 59],
        self::HOUR   => [0, 23],
        self::DAY    => [1, 31],
        self::MONTH  => [1, 12],
        self::WEEK   => [0, 6],
    ];

    public function __construct(string $expression)
    {
        $parts = preg_split('/\s+/', trim($expression));
        $parts = $parts === false ? [] : $parts;

        if (count($parts) === 5) {
            array_unshift($parts, '0');
        }

        if (count($parts) !== 6) {
            throw new InvalidArgumentException(
                "Invalid cron expression: {$expression}. Expected 5 or 6 fields."
            );
        }

        $this->parts = $parts;
    }

    /**
     * 判定给定时间戳是否命中（忽略秒以下部分）
     */
    public function isDue(int $timestamp, ?DateTimeZone $timezone = null): bool
    {
        $dt = new DateTimeImmutable('@' . $timestamp);
        if ($timezone !== null) {
            $dt = $dt->setTimezone($timezone);
        }

        $values = [
            self::SECOND => (int) $dt->format('s'),
            self::MINUTE => (int) $dt->format('i'),
            self::HOUR   => (int) $dt->format('H'),
            self::DAY    => (int) $dt->format('j'),
            self::MONTH  => (int) $dt->format('n'),
            self::WEEK   => (int) $dt->format('w'),
        ];

        $dayMatched  = $this->matchField(self::DAY, $values[self::DAY]);
        $weekMatched = $this->matchField(self::WEEK, $values[self::WEEK]);
        $dayIsAny    = $this->parts[self::DAY]  === '*';
        $weekIsAny   = $this->parts[self::WEEK] === '*';

        // 日 与 周：两者都指定时为 OR，否则按各自字段
        if ($dayIsAny && $weekIsAny) {
            $dateOk = true;
        } elseif ($dayIsAny) {
            $dateOk = $weekMatched;
        } elseif ($weekIsAny) {
            $dateOk = $dayMatched;
        } else {
            $dateOk = $dayMatched || $weekMatched;
        }

        if (!$dateOk) {
            return false;
        }

        return $this->matchField(self::SECOND, $values[self::SECOND])
            && $this->matchField(self::MINUTE, $values[self::MINUTE])
            && $this->matchField(self::HOUR, $values[self::HOUR])
            && $this->matchField(self::MONTH, $values[self::MONTH]);
    }

    /**
     * 计算下次命中的时间戳（含当前给定时间）
     */
    public function nextRun(int $fromTimestamp, ?DateTimeZone $timezone = null, int $maxIterations = 31622400): int
    {
        $ts = $fromTimestamp;
        for ($i = 0; $i < $maxIterations; $i++, $ts++) {
            if ($this->isDue($ts, $timezone)) {
                return $ts;
            }
        }

        throw new RuntimeException('Unable to find next run in reasonable iterations.');
    }

    /**
     * 获取表达式某部分
     */
    public function getPart(int $index): string
    {
        return $this->parts[$index];
    }

    /*
     *--------------------------------------------------------------------------
     * 单字段匹配
     *--------------------------------------------------------------------------
     */

    private function matchField(int $index, int $value): bool
    {
        $field       = $this->parts[$index];
        [$min, $max] = self::$ranges[$index];

        // 多个子表达式用 , 分隔
        foreach (explode(',', $field) as $segment) {
            if ($this->matchSegment($segment, $value, $min, $max)) {
                return true;
            }
        }

        return false;
    }

    private function matchSegment(string $seg, int $value, int $min, int $max): bool
    {
        $step = 1;

        // 处理 /N 步长
        if (str_contains($seg, '/')) {
            [$range, $stepStr] = explode('/', $seg, 2);
            $step              = max(1, (int) $stepStr);
            if ($range === '' || $range === '*') {
                $range = "{$min}-{$max}";
            }
        } else {
            $range = $seg;
        }

        if ($range === '*') {
            return ($value - $min) % $step === 0;
        }

        if (str_contains($range, '-')) {
            [$lo, $hi] = array_map('intval', explode('-', $range, 2));
            if ($value < $lo || $value > $hi) {
                return false;
            }

            return ($value - $lo) % $step === 0;
        }

        // 精确整数
        $num = (int) $range;
        if ($step === 1) {
            return $num === $value;
        }

        // 形如 5/10：范围 5..max，步长 10
        if ($value < $num || $value > $max) {
            return false;
        }

        return ($value - $num) % $step === 0;
    }
}
