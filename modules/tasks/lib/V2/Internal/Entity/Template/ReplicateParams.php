<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Entity\Template;

use Bitrix\Tasks\V2\Internal\Entity\Trait\MapTypeTrait;
use Bitrix\Tasks\V2\Internal\Entity\ValueObjectInterface;

class ReplicateParams implements ValueObjectInterface
{
	use MapTypeTrait;

	public const DEFAULT_TIME = '05:00';

	public function __construct(
		public readonly ?Period $period = null,
		public readonly ?int $everyDay = null,
		public readonly ?string $workdayOnly = null,
		public readonly ?int $dailyMonthInterval = null,
		public readonly ?int $everyWeek = null,
		public readonly ?array $weekDays = null,
		public readonly ?MonthlyType $monthlyType = null,
		public readonly ?int $monthlyDayNum = null,
		public readonly ?int $monthlyMonthNum1 = null,
		public readonly ?int $monthlyWeekDayNum = null,
		public readonly ?int $monthlyWeekDay = null,
		public readonly ?int $monthlyMonthNum2 = null,
		public readonly ?YearlyType $yearlyType = null,
		public readonly ?int $yearlyDayNum = null,
		public readonly ?int $yearlyMonth1 = null,
		public readonly ?int $yearlyWeekDayNum = null,
		public readonly ?int $yearlyWeekDay = null,
		public readonly ?int $yearlyMonth2 = null,
		public readonly ?string $time = null,
		public readonly ?string $timezoneOffset = null,
		public readonly ?string $startDate = null,
		public readonly ?RepeatTill $repeatTill = null,
		public readonly ?string $endDate = null,
		public readonly ?int $times = null,
		public readonly ?string $nextExecutionTime = null,
		public readonly ?string $deadlineOffset = null,
	)
	{
	}

	public function toArray(): array
	{
		return [
			'period' => $this->period?->value,
			'everyDay' => $this->everyDay,
			'workdayOnly' => $this->workdayOnly,
			'dailyMonthInterval' => $this->dailyMonthInterval,
			'everyWeek' => $this->everyWeek,
			'weekDays' => $this->weekDays,
			'monthlyType' => $this->monthlyType?->value,
			'monthlyDayNum' => $this->monthlyDayNum,
			'monthlyMonthNum1' => $this->monthlyMonthNum1,
			'monthlyWeekDayNum' => $this->monthlyWeekDayNum,
			'monthlyWeekDay' => $this->monthlyWeekDay,
			'monthlyMonthNum2' => $this->monthlyMonthNum2,
			'yearlyType' => $this->yearlyType?->value,
			'yearlyDayNum' => $this->yearlyDayNum,
			'yearlyMonth1' => $this->yearlyMonth1,
			'yearlyWeekDayNum' => $this->yearlyWeekDayNum,
			'yearlyWeekDay' => $this->yearlyWeekDay,
			'yearlyMonth2' => $this->yearlyMonth2,
			'time' => $this->time,
			'timezoneOffset' => $this->timezoneOffset,
			'startDate' => $this->startDate,
			'repeatTill' => $this->repeatTill?->value,
			'endDate' => $this->endDate,
			'times' => $this->times,
			'nextExecutionTime' => $this->nextExecutionTime,
			'deadlineOffset' => $this->deadlineOffset,
		];
	}

	public static function mapFromArray(array $props): static
	{
		return new self(
			period: static::mapBackedEnum($props, 'period', Period::class),
			everyDay: static::mapInteger($props, 'everyDay'),
			workdayOnly: static::mapString($props, 'workdayOnly'),
			dailyMonthInterval: static::mapInteger($props, 'dailyMonthInterval'),
			everyWeek: static::mapInteger($props, 'everyWeek'),
			weekDays: static::mapArray($props, 'weekDays', 'intval'),
			monthlyType: static::mapBackedEnum($props, 'monthlyType', MonthlyType::class),
			monthlyDayNum: static::mapInteger($props, 'monthlyDayNum'),
			monthlyMonthNum1: static::mapInteger($props, 'monthlyMonthNum1'),
			monthlyWeekDayNum: static::mapInteger($props, 'monthlyWeekDayNum'),
			monthlyWeekDay: static::mapInteger($props, 'monthlyWeekDay'),
			monthlyMonthNum2: static::mapInteger($props, 'monthlyMonthNum2'),
			yearlyType: static::mapBackedEnum($props, 'yearlyType', YearlyType::class),
			yearlyDayNum: static::mapInteger($props, 'yearlyDayNum'),
			yearlyMonth1: static::mapInteger($props, 'yearlyMonth1'),
			yearlyWeekDayNum: static::mapInteger($props, 'yearlyWeekDayNum'),
			yearlyWeekDay: static::mapInteger($props, 'yearlyWeekDay'),
			yearlyMonth2: static::mapInteger($props, 'yearlyMonth2'),
			time: static::mapString($props, 'time'),
			timezoneOffset: static::mapString($props, 'timezoneOffset'),
			startDate: static::mapString($props, 'startDate'),
			repeatTill: static::mapBackedEnum($props, 'repeatTill', RepeatTill::class),
			endDate: static::mapString($props, 'endDate'),
			times: static::mapInteger($props, 'times'),
			nextExecutionTime: static::mapString($props, 'nextExecutionTime'),
			deadlineOffset: static::mapString($props, 'deadlineOffset'),
		);
	}
}
