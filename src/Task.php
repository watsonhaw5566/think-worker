<?php

declare(strict_types=1);

namespace think\worker;

use think\App;

/**
 * Crontab 定时任务抽象基类
 *
 * API 设计与 watsonhaw/think-cron 保持一致，同时扩展秒级调度能力。
 *
 * 用法：
 *   class DemoTask extends Task {
 *       protected function configure() {
 *           $this->dailyAt('02:00')
 *                ->withoutOverlapping(60)
 *                ->onOneServer()
 *                ->tries(3, 30);
 *       }
 *       protected function execute() { ... }
 *   }
 */
abstract class Task
{
    /*
     *--------------------------------------------------------------------------
     * 内部状态字段（由 Fluent API 写入，由 Scheduler 读取）
     *--------------------------------------------------------------------------
     */

    /** 6 段 cron 表达式：秒 分 时 日 月 周 */
    protected string $expression = '* * * * * *';

    protected string $name      = '';
    protected ?string $timezone = null;
    protected bool $enabled     = true;

    /** 仅在一台服务器执行：null=未设置, true=开启, false=显式关闭 */
    protected ?bool $onOneServer = null;

    /** 防重叠执行锁过期（秒），0 表示不启用 */
    protected int $lockExpireSec = 0;

    /** 最大尝试次数（含首次），1=不重试 */
    protected int $tries = 1;

    /** 重试间隔（秒） */
    protected int $retryDelaySec = 0;

    /** @var array<int,array{0:string,1:string,2:bool}> 时区检查区间 */
    protected array $betweenRules = [];

    /** @var callable[] */
    protected array $whenCallbacks = [];

    /** @var callable[] */
    protected array $skipCallbacks = [];

    protected App $app;

    final public function __construct(App $app)
    {
        $this->app = $app;
        $this->configure();
    }

    /**
     * 配置任务的执行周期与行为（构造时自动调用一次）
     */
    abstract protected function configure(): void;

    /**
     * 执行任务的业务逻辑
     */
    abstract protected function execute(): void;

    /*
     *--------------------------------------------------------------------------
     * 频率设置（Fluent API，均返回 $this）
     *--------------------------------------------------------------------------
     */

    /** 设置原生 6 段 cron 表达式（秒 分 时 日 月 周，秒可省略） */
    public function expression(string $expression): static
    {
        $parts = preg_split('/\s+/', trim($expression));
        if ($parts === false) {
            $parts = [trim($expression)];
        }
        if (count($parts) === 5) {
            array_unshift($parts, '0'); // 补秒位=0
        }
        $this->expression = implode(' ', $parts);

        return $this;
    }

    /** 每分钟 */
    public function everyMinute(): static
    {
        return $this->expression('0 * * * * *');
    }

    /** 每 5 分钟 */
    public function everyFiveMinutes(): static
    {
        return $this->everyMinutes(5);
    }

    /** 每 10 分钟 */
    public function everyTenMinutes(): static
    {
        return $this->everyMinutes(10);
    }

    /** 每 30 分钟 */
    public function everyThirtyMinutes(): static
    {
        return $this->everyMinutes(30);
    }

    /** 每 N 分钟 */
    public function everyMinutes(int $minutes): static
    {
        $minutes = max(1, $minutes);

        return $this->expression("0 */{$minutes} * * * *");
    }

    /** 每小时整点（第 0 分） */
    public function hourly(): static
    {
        return $this->hourlyAt(0);
    }

    /** 每小时的第 $offset 分钟 */
    public function hourlyAt(int $offset): static
    {
        return $this->expression("0 {$offset} * * * *");
    }

    /** 每天 00:00 */
    public function daily(): static
    {
        return $this->dailyAt('00:00');
    }

    /** 每天指定时刻，如 dailyAt('13:00') 或 at('13:00') */
    public function dailyAt(string $time): static
    {
        return $this->at($time);
    }

    /** @see dailyAt() */
    public function at(string $time): static
    {
        [$hour, $minute] = $this->parseTime($time);

        return $this->expression("0 {$minute} {$hour} * * *");
    }

    /** 每天两次（默认 01:00 与 13:00） */
    public function twiceDaily(int $first = 1, int $second = 13): static
    {
        return $this->expression("0 0 {$first},{$second} * * *");
    }

    /** 工作日 */
    public function weekdays(): static
    {
        $parts            = explode(' ', $this->expression);
        $parts[5]         = '1-5';
        $this->expression = implode(' ', $parts);

        return $this;
    }

    /** 周末 */
    public function weekends(): static
    {
        $parts            = explode(' ', $this->expression);
        $parts[5]         = '0,6';
        $this->expression = implode(' ', $parts);

        return $this;
    }

    public function mondays(): static
    {
        return $this->days(1);
    }
    public function tuesdays(): static
    {
        return $this->days(2);
    }
    public function wednesdays(): static
    {
        return $this->days(3);
    }
    public function thursdays(): static
    {
        return $this->days(4);
    }
    public function fridays(): static
    {
        return $this->days(5);
    }
    public function saturdays(): static
    {
        return $this->days(6);
    }
    public function sundays(): static
    {
        return $this->days(0);
    }

    /**
     * 指定每周的若干天（0=周日 … 6=周六）
     *
     * 用法：days(1,3,5) 或 days([1,3,5])
     *
     * @param int|array<int> ...$days
     */
    public function days(int|array ...$days): static
    {
        $flatten = [];
        foreach ($days as $d) {
            if (is_array($d)) {
                foreach ($d as $item) {
                    $flatten[] = $item;
                }
            } else {
                $flatten[] = $d;
            }
        }
        $parts            = explode(' ', $this->expression);
        $parts[5]         = implode(',', $flatten);
        $this->expression = implode(' ', $parts);

        return $this;
    }

    /** 每周（默认周一 00:00） */
    public function weekly(): static
    {
        return $this->weeklyOn(1, '00:00');
    }

    /** 每周指定日指定时间 */
    public function weeklyOn(int $dayOfWeek, string $time = '00:00'): static
    {
        [$hour, $minute] = $this->parseTime($time);

        return $this->expression("0 {$minute} {$hour} * * {$dayOfWeek}");
    }

    /** 每月 1 号 00:00 */
    public function monthly(): static
    {
        return $this->monthlyOn(1, '00:00');
    }

    /** 每月指定日指定时间 */
    public function monthlyOn(int $day, string $time = '00:00'): static
    {
        [$hour, $minute] = $this->parseTime($time);

        return $this->expression("0 {$minute} {$hour} {$day} * *");
    }

    /** 每月两次（默认 1 号与 16 号） */
    public function twiceMonthly(int $first = 1, int $second = 16): static
    {
        return $this->expression("0 0 0 {$first},{$second} * *");
    }

    /** 每季度 1 号 00:00 */
    public function quarterly(): static
    {
        return $this->expression('0 0 0 1 1,4,7,10 *');
    }

    /** 每年 1 月 1 日 00:00 */
    public function yearly(): static
    {
        return $this->expression('0 0 0 1 1 *');
    }

    /*
     *--------------------------------------------------------------------------
     * 秒级扩展（think-cron 不具备的能力）
     *--------------------------------------------------------------------------
     */

    /** 每秒执行 */
    public function everySecond(): static
    {
        return $this->expression('* * * * * *');
    }

    /** 每 N 秒执行 */
    public function everySeconds(int $seconds): static
    {
        $seconds = max(1, min(59, $seconds));

        return $this->expression("*/{$seconds} * * * * *");
    }

    /** 每分钟的指定秒数组执行，例如：everySecondAt([0, 15, 30, 45]) */
    public function everySecondAt(array $seconds): static
    {
        $seconds = array_map(fn ($s) => max(0, min(59, (int) $s)), $seconds);

        return $this->expression(implode(',', $seconds) . ' * * * * *');
    }

    /*
     *--------------------------------------------------------------------------
     * 控制与元信息
     *--------------------------------------------------------------------------
     */

    public function name(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function enable(): static
    {
        $this->enabled = true;

        return $this;
    }
    public function disable(): static
    {
        $this->enabled = false;

        return $this;
    }

    /** 设置任务时区 */
    public function timezone(string $timezone): static
    {
        $this->timezone = $timezone;

        return $this;
    }

    /**
     * 仅在一台服务器执行（多机部署推荐）
     */
    public function onOneServer(): static
    {
        $this->onOneServer = true;

        return $this;
    }

    /** 显式关闭单服务器（覆盖全局默认） */
    public function withoutOnOneServer(): static
    {
        $this->onOneServer = false;

        return $this;
    }

    /**
     * 防重叠执行（默认锁过期 1440 分钟 = 24 小时）
     *
     * @param int $minutes 锁过期分钟数
     */
    public function withoutOverlapping(int $minutes = 1440): static
    {
        $this->lockExpireSec = max(1, $minutes) * 60;

        return $this;
    }

    /**
     * 失败重试
     *
     * @param int $tries 最大尝试次数（含首次执行）；1 = 不重试
     * @param int $delay 每次重试之间等待秒数
     */
    public function tries(int $tries = 1, int $delay = 0): static
    {
        $this->tries         = max(1, $tries);
        $this->retryDelaySec = max(0, $delay);

        return $this;
    }

    /**
     * 仅在指定时间区间内执行（支持跨午夜，如 between('22:00', '01:00')）
     *
     * @param string $start HH:MM
     * @param string $end   HH:MM
     */
    public function between(string $start, string $end): static
    {
        $this->betweenRules[] = [$start, $end, true];

        return $this;
    }

    /** 在指定时间区间内跳过执行 */
    public function unlessBetween(string $start, string $end): static
    {
        $this->betweenRules[] = [$start, $end, false];

        return $this;
    }

    /** 条件为真时才执行（可叠加多个，全部为真才通过） */
    public function when(callable $callback): static
    {
        $this->whenCallbacks[] = $callback;

        return $this;
    }

    /** 条件为真时跳过执行（可叠加多个，任一为真即跳过） */
    public function skip(callable $callback): static
    {
        $this->skipCallbacks[] = $callback;

        return $this;
    }

    /*
     *--------------------------------------------------------------------------
     * 读取器（Scheduler 使用）
     *--------------------------------------------------------------------------
     */

    public function getName(): string
    {
        return $this->name ?: static::class;
    }
    public function getExpression(): string
    {
        return $this->expression;
    }
    public function getTimezone(): ?string
    {
        return $this->timezone;
    }
    public function isEnabled(): bool
    {
        return $this->enabled;
    }
    public function getOnOneServer(): ?bool
    {
        return $this->onOneServer;
    }
    public function getLockExpireSec(): int
    {
        return $this->lockExpireSec;
    }
    public function getTries(): int
    {
        return $this->tries;
    }
    public function getRetryDelaySec(): int
    {
        return $this->retryDelaySec;
    }
    /** @return array<int,array{0:string,1:string,2:bool}> */
    public function getBetweenRules(): array
    {
        return $this->betweenRules;
    }
    /** @return callable[] */
    public function getWhenCallbacks(): array
    {
        return $this->whenCallbacks;
    }
    /** @return callable[] */
    public function getSkipCallbacks(): array
    {
        return $this->skipCallbacks;
    }

    /** 运行任务（由 Scheduler 调用） */
    public function run(): void
    {
        if ($this->enabled) {
            $this->execute();
        }
    }

    /*
     *--------------------------------------------------------------------------
     * 内部工具
     *--------------------------------------------------------------------------
     */

    /**
     * @param string $time
     * @return array{0:int,1:int}
     */
    private function parseTime(string $time): array
    {
        $parts = explode(':', $time);
        $h     = (int) $parts[0];
        $m     = (int) ($parts[1] ?? 0);

        return [$h, $m];
    }
}
