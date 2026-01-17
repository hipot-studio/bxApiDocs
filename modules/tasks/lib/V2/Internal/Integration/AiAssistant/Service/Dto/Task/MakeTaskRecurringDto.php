<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto\Task;

use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Validation\Rule\Min;
use Bitrix\Main\Validation\Rule\NotEmpty;
use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Tasks\V2\Internal\Entity\Template\MonthlyType;
use Bitrix\Tasks\V2\Internal\Entity\Template\Period;
use Bitrix\Tasks\V2\Internal\Entity\Template\RepeatTill;
use Bitrix\Tasks\V2\Internal\Entity\Template\ReplicateParams;
use Bitrix\Tasks\V2\Internal\Entity\Template\YearlyType;
use Bitrix\Tasks\V2\Internal\Entity\Trait\MapTypeTrait;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto\Trait\MapDateTimeTrait;

class MakeTaskRecurringDto
{
	use MapTypeTrait;
	use MapDateTimeTrait;

	private function __construct(
		#[PositiveNumber]
		public readonly ?int $taskId = null,
		public readonly ?bool $workdayOnly = null,
		#[PositiveNumber]
		public readonly ?int $everyDay = null,
		#[PositiveNumber]
		public readonly ?int $everyWeek = null,
		#[NotEmpty]
		public readonly ?Period $period = null,
		#[Min(0)]
		public readonly ?int $dailyMonthInterval = null,
		public readonly ?array $weekDays = null,
		#[NotEmpty]
		public readonly ?MonthlyType $monthlyType = null,
		#[PositiveNumber]
		public readonly ?int $monthlyDayNum = null,
		#[PositiveNumber]
		public readonly ?int $monthlyMonthNum1 = null,
		#[Min(0)]
		public readonly ?int $monthlyWeekDayNum = null,
		#[Min(0)]
		public readonly ?int $monthlyWeekDay = null,
		#[PositiveNumber]
		public readonly ?int $monthlyMonthNum2 = null,
		#[NotEmpty]
		public readonly ?YearlyType $yearlyType = null,
		#[PositiveNumber]
		public readonly ?int $yearlyDayNum = null,
		#[Min(0)]
		public readonly ?int $yearlyMonth1 = null,
		#[Min(0)]
		public readonly ?int $yearlyWeekDayNum = null,
		#[Min(0)]
		public readonly ?int $yearlyWeekDay = null,
		#[Min(0)]
		public readonly ?int $yearlyMonth2 = null,
		#[NotEmpty]
		public readonly ?string $time = null,
		public readonly ?DateTime $startDate = null,
		#[NotEmpty]
		public readonly ?RepeatTill $repeatTill = null,
		public readonly ?DateTime $endDate = null,
		#[Min(0)]
		public readonly ?int $times = null,
	)
	{
	}

	public static function fromArray(array $props): self
	{
		return new static(
			taskId: static::mapInteger($props, 'taskId'),
			workdayOnly: static::mapBool($props, 'workdayOnly') ?? false,
			everyDay: static::mapInteger($props, 'everyDay') ?? 1,
			everyWeek: static::mapInteger($props, 'everyWeek') ?? 1,
			period: static::mapBackedEnum($props, 'period', Period::class) ?? Period::Daily,
			dailyMonthInterval: static::mapInteger($props, 'dailyMonthInterval') ?? 0,
			weekDays: static::mapArray($props, 'weekDays', 'intval') ?? [],
			monthlyType: static::mapBackedEnum($props, 'monthlyType', MonthlyType::class) ?? MonthlyType::MonthDay,
			monthlyDayNum: static::mapInteger($props, 'monthlyDayNum') ?? 1,
			monthlyMonthNum1: static::mapInteger($props, 'monthlyMonthNum1') ?? 1,
			monthlyWeekDayNum: static::mapInteger($props, 'monthlyWeekDayNum') ?? 0,
			monthlyWeekDay: static::mapInteger($props, 'monthlyWeekDay') ?? 0,
			monthlyMonthNum2: static::mapInteger($props, 'monthlyMonthNum2') ?? 1,
			yearlyType: static::mapBackedEnum($props, 'yearlyType', YearlyType::class) ?? YearlyType::MonthDay,
			yearlyDayNum: static::mapInteger($props, 'yearlyDayNum') ?? 1,
			yearlyMonth1: static::mapInteger($props, 'yearlyMonth1') ?? 0,
			yearlyWeekDayNum: static::mapInteger($props, 'yearlyWeekDayNum') ?? 0,
			yearlyWeekDay: static::mapInteger($props, 'yearlyWeekDay') ?? 0,
			yearlyMonth2: static::mapInteger($props, 'yearlyMonth2') ?? 0,
			time: static::mapString($props, 'time') ?? ReplicateParams::DEFAULT_TIME,
			startDate: static::mapFormattedDateTime($props, 'startDate'),
			repeatTill: static::mapBackedEnum($props, 'repeatTill', RepeatTill::class) ?? RepeatTill::ENDLESS,
			endDate: static::mapFormattedDateTime($props, 'endDate'),
			times: static::mapInteger($props, 'times') ?? 0,
		);
	}
}
