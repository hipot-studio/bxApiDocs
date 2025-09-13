<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Entity\Template;

class ReplicateParams
{
	public function __construct(
		public readonly ?string $period = null,
		public readonly ?string $everyDay = null,
		public readonly ?string $workdayOnly = null,
		public readonly ?string $dailyMonthInterval = null,
		public readonly ?string $everyWeek = null,
		public readonly ?string $monthlyType = null,
		public readonly ?string $monthlyDayNum = null,
		public readonly ?string $monthlyMonthNum1 = null,
		public readonly ?string $monthlyWeekDayNum = null,
		public readonly ?string $monthlyWeekDay = null,
		public readonly ?string $monthlyMonthNum2 = null,
		public readonly ?string $yearlyType = null,
		public readonly ?string $yearlyDayNum = null,
		public readonly ?string $yearlyMonth1 = null,
		public readonly ?string $yearlyWeekDayNum = null,
		public readonly ?string $yearlyWeekDay = null,
		public readonly ?string $yearlyMonth2 = null,
		public readonly ?string $time = null,
		public readonly ?string $timezoneOffset = null,
		public readonly ?string $startDate = null,
		public readonly ?string $repeatTill = null,
		public readonly ?string $endDate = null,
		public readonly ?string $times = null
	) {}

	public function toArray(): array
	{
		return [
			'period' => $this->period,
			'everyDay' => $this->everyDay,
			'workdayOnly' => $this->workdayOnly,
			'dailyMonthInterval' => $this->dailyMonthInterval,
			'everyWeek' => $this->everyWeek,
			'monthlyType' => $this->monthlyType,
			'monthlyDayNum' => $this->monthlyDayNum,
			'monthlyMonthNum1' => $this->monthlyMonthNum1,
			'monthlyWeekDayNum' => $this->monthlyWeekDayNum,
			'monthlyWeekDay' => $this->monthlyWeekDay,
			'monthlyMonthNum2' => $this->monthlyMonthNum2,
			'yearlyType' => $this->yearlyType,
			'yearlyDayNum' => $this->yearlyDayNum,
			'yearlyMonth1' => $this->yearlyMonth1,
			'yearlyWeekDayNum' => $this->yearlyWeekDayNum,
			'yearlyWeekDay' => $this->yearlyWeekDay,
			'yearlyMonth2' => $this->yearlyMonth2,
			'time' => $this->time,
			'timezoneOffset' => $this->timezoneOffset,
			'startDate' => $this->startDate,
			'repeatTill' => $this->repeatTill,
			'endDate' => $this->endDate,
			'times' => $this->times,
		];
	}

	public static function mapFromArray(array $props): self
	{
		return new self(
			period: $props['period'] ?? null,
			everyDay: $props['everyDay'] ?? null,
			workdayOnly: $props['workdayOnly'] ?? null,
			dailyMonthInterval: $props['dailyMonthInterval'] ?? null,
			everyWeek: $props['everyWeek'] ?? null,
			monthlyType: $props['monthlyType'] ?? null,
			monthlyDayNum: $props['monthlyDayNum'] ?? null,
			monthlyMonthNum1: $props['monthlyMonthNum1'] ?? null,
			monthlyWeekDayNum: $props['monthlyWeekDayNum'] ?? null,
			monthlyWeekDay: $props['monthlyWeekDay'] ?? null,
			monthlyMonthNum2: $props['monthlyMonthNum2'] ?? null,
			yearlyType: $props['yearlyType'] ?? null,
			yearlyDayNum: $props['yearlyDayNum'] ?? null,
			yearlyMonth1: $props['yearlyMonth1'] ?? null,
			yearlyWeekDayNum: $props['yearlyWeekDayNum'] ?? null,
			yearlyWeekDay: $props['yearlyWeekDay'] ?? null,
			yearlyMonth2: $props['yearlyMonth2'] ?? null,
			time: $props['time'] ?? null,
			timezoneOffset: $props['timezoneOffset'] ?? null,
			startDate: $props['startDate'] ?? null,
			repeatTill: $props['repeatTill'] ?? null,
			endDate: $props['endDate'] ?? null,
			times: $props['times'] ?? null,
		);
	}
}
