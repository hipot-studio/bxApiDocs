<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto\Task;

use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Validation\Rule\Min;
use Bitrix\Main\Validation\Rule\NotEmpty;
use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Tasks\Replication\Template\Enum\DeadlineType;
use Bitrix\Tasks\Replication\Template\Enum\RegularityType;
use Bitrix\Tasks\V2\Internal\Entity\Template\MonthlyType;
use Bitrix\Tasks\V2\Internal\Entity\Template\Period;
use Bitrix\Tasks\V2\Internal\Entity\Template\RepeatTill;
use Bitrix\Tasks\V2\Internal\Entity\Template\ReplicateParams;
use Bitrix\Tasks\V2\Internal\Entity\Template\YearlyType;
use Bitrix\Tasks\V2\Internal\Entity\Trait\MapTypeTrait;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Exception\DtoValidationException;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto\Trait\MapDateTimeTrait;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Validation\Rule\NullableMin;

class MakeTaskRecurringDto
{
	use MapTypeTrait;
	use MapDateTimeTrait;

	public const TIME_PATTERN = '^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$';
	public const MONTHLY_DAY_MAX = 31;
	public const MONTHLY_WEEK_DAY_NUM_MAX = 4;
	public const MONTHLY_WEEK_DAY_MAX = 6;
	public const YEARLY_DAY_MAX = 31;
	public const YEARLY_MONTH_MAX = 11;
	public const YEARLY_WEEK_DAY_NUM_MAX = 4;
	public const YEARLY_WEEK_DAY_MAX = 6;

	private function __construct(
		public readonly ?int $taskId = null,
		public readonly ?bool $workdayOnly = null,
		#[NullableMin(0)]
		public readonly ?int $createOffset = null,
		#[NullableMin(0)]
		public readonly ?int $deadlineOffset = null,
		#[NullableMin(0)]
		public readonly ?int $completionDelay = null,
		public readonly RegularityType $regularityType = RegularityType::Schedule,
		public readonly ?bool $matchesWorkTime = null,
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
		public readonly ?int $monthlyDayNum = null,
		public readonly ?int $monthlyMonthNum1 = null,
		public readonly ?int $monthlyWeekDayNum = null,
		public readonly ?int $monthlyWeekDay = null,
		public readonly ?int $monthlyMonthNum2 = null,
		#[NotEmpty]
		public readonly ?YearlyType $yearlyType = null,
		public readonly ?int $yearlyDayNum = null,
		public readonly ?int $yearlyMonth1 = null,
		public readonly ?int $yearlyWeekDayNum = null,
		public readonly ?int $yearlyWeekDay = null,
		public readonly ?int $yearlyMonth2 = null,
		#[NotEmpty]
		public readonly ?string $time = null,
		public readonly ?DateTime $startDate = null,
		#[NotEmpty]
		public readonly ?RepeatTill $repeatTill = null,
		public readonly ?DateTime $endDate = null,
		#[Min(0)]
		public readonly ?int $times = null,
		public readonly ?DeadlineType $deadlineType = null,
		public readonly bool $deadlineSpecified = false,
	)
	{
	}

	public static function fromArray(array $props): self
	{
		$userId = static::mapInteger($props, 'userId');
		$anchorToDeadline = static::mapArray($props, 'anchorToDeadline');
		$deadlineOffset = static::mapInteger($props, 'deadlineOffset');
		$deadlineType = static::mapBackedEnum($props, 'deadlineType', DeadlineType::class);
		if (
			$anchorToDeadline === null
			&& !array_key_exists('deadlineOffset', $props)
			&& $deadlineType === DeadlineType::CreationDay
		)
		{
			$deadlineOffset = 0;
		}
		if ($anchorToDeadline !== null && $deadlineOffset !== null)
		{
			throw new DtoValidationException(
				'anchorToDeadline and deadlineOffset cannot be used together.',
			);
		}

		$regularityType =
			$anchorToDeadline !== null
				? RegularityType::Deadline
				: static::mapBackedEnum(
					$props,
					'regularityType',
					RegularityType::class,
					RegularityType::Schedule,
				) ?? RegularityType::Schedule
		;
		$completionDelay = static::mapInteger($props, 'completionDelay');
		$period = static::mapBackedEnum($props, 'period', Period::class) ?? Period::Daily;
		$weekDays = static::mapWeekDays($props, $period);
		$monthlyType = static::mapBackedEnum($props, 'monthlyType', MonthlyType::class) ?? MonthlyType::MonthDay;
		$monthlyFields = static::mapMonthlyFields($props, $period, $monthlyType);
		$yearlyType = static::mapBackedEnum($props, 'yearlyType', YearlyType::class) ?? YearlyType::MonthDay;
		$yearlyFields = static::mapYearlyFields($props, $period, $yearlyType);
		$time = static::mapTime($props);

		return new static(
			taskId: static::mapInteger($props, 'taskId'),
			workdayOnly: static::mapBool($props, 'workdayOnly') ?? false,
			createOffset:
				$anchorToDeadline !== null
					? static::mapInteger($anchorToDeadline, 'leadDays') ?? 0
					: null,
			deadlineOffset:
				$anchorToDeadline === null
					? $deadlineOffset
					: null,
			completionDelay:
				$regularityType === RegularityType::AfterCompletion
					? $completionDelay ?? 0
					: $completionDelay,
			regularityType: $regularityType,
			matchesWorkTime: static::mapBool($props, 'matchesWorkTime'),
			everyDay: static::mapInteger($props, 'everyDay') ?? 1,
			everyWeek: static::mapInteger($props, 'everyWeek') ?? 1,
			period: $period,
			dailyMonthInterval: static::mapInteger($props, 'dailyMonthInterval') ?? 0,
			weekDays: $weekDays,
			monthlyType: $monthlyType,
			monthlyDayNum: $monthlyFields['monthlyDayNum'],
			monthlyMonthNum1: $monthlyFields['monthlyMonthNum1'],
			monthlyWeekDayNum: $monthlyFields['monthlyWeekDayNum'],
			monthlyWeekDay: $monthlyFields['monthlyWeekDay'],
			monthlyMonthNum2: $monthlyFields['monthlyMonthNum2'],
			yearlyType: $yearlyType,
			yearlyDayNum: $yearlyFields['yearlyDayNum'],
			yearlyMonth1: $yearlyFields['yearlyMonth1'],
			yearlyWeekDayNum: $yearlyFields['yearlyWeekDayNum'],
			yearlyWeekDay: $yearlyFields['yearlyWeekDay'],
			yearlyMonth2: $yearlyFields['yearlyMonth2'],
			time: $time,
			startDate: static::mapFormattedDateTime($props, 'startDate', $userId),
			repeatTill: static::mapBackedEnum($props, 'repeatTill', RepeatTill::class) ?? RepeatTill::ENDLESS,
			endDate: static::mapFormattedDateTime($props, 'endDate', $userId),
			times: static::mapInteger($props, 'times') ?? 0,
			deadlineType:
				$anchorToDeadline === null
					? $deadlineType
					: null,
			deadlineSpecified: array_key_exists('deadlineOffset', $props) || array_key_exists('deadlineType', $props),
		);
	}

	private static function mapTime(array $props): string
	{
		if (!array_key_exists('time', $props) || $props['time'] === null)
		{
			return ReplicateParams::DEFAULT_TIME;
		}

		$time = $props['time'];
		if (!is_string($time) || preg_match('/' . self::TIME_PATTERN . '/D', $time) !== 1)
		{
			throw new DtoValidationException(
				"time must use the 24-hour 'HH:MM' or 'HH:MM:SS' format from 00:00 to 23:59.",
			);
		}

		return substr($time, 0, 5);
	}

	private static function mapWeekDays(array $props, Period $period): array
	{
		$weekDays = static::mapArray($props, 'weekDays', 'intval') ?? [];
		if ($period !== Period::Weekly)
		{
			return $weekDays;
		}

		if ($weekDays === [])
		{
			throw new DtoValidationException('weekDays must contain at least one weekday for weekly recurrence.');
		}

		$uniqueWeekDays = [];
		foreach ($weekDays as $weekDay)
		{
			if ($weekDay < 1 || $weekDay > 7)
			{
				throw new DtoValidationException('weekDays must contain only values from 1 to 7.');
			}

			if (isset($uniqueWeekDays[$weekDay]))
			{
				throw new DtoValidationException('weekDays must contain unique values.');
			}

			$uniqueWeekDays[$weekDay] = true;
		}

		return $weekDays;
	}

	/**
	 * @return array{
	 *     monthlyDayNum: ?int,
	 *     monthlyMonthNum1: ?int,
	 *     monthlyWeekDayNum: ?int,
	 *     monthlyWeekDay: ?int,
	 *     monthlyMonthNum2: ?int
	 * }
	 */
	private static function mapMonthlyFields(array $props, Period $period, MonthlyType $monthlyType): array
	{
		if ($period !== Period::Monthly)
		{
			return [
				'monthlyDayNum' => static::mapInteger($props, 'monthlyDayNum') ?? 1,
				'monthlyMonthNum1' => static::mapInteger($props, 'monthlyMonthNum1') ?? 1,
				'monthlyWeekDayNum' => static::mapInteger($props, 'monthlyWeekDayNum') ?? 0,
				'monthlyWeekDay' => static::mapInteger($props, 'monthlyWeekDay') ?? 0,
				'monthlyMonthNum2' => static::mapInteger($props, 'monthlyMonthNum2') ?? 1,
			];
		}

		if ($monthlyType === MonthlyType::MonthDay)
		{
			static::rejectProvidedFields(
				$props,
				['monthlyWeekDayNum', 'monthlyWeekDay', 'monthlyMonthNum2'],
				'Only monthlyDayNum and monthlyMonthNum1 can be used with monthly month-day recurrence.',
			);

			$monthlyDayNum = static::mapInteger($props, 'monthlyDayNum') ?? 1;
			$monthlyMonthNum1 = static::mapInteger($props, 'monthlyMonthNum1') ?? 1;
			static::validateRange($monthlyDayNum, 1, self::MONTHLY_DAY_MAX, 'monthlyDayNum');
			static::validateMinimum($monthlyMonthNum1, 1, 'monthlyMonthNum1');

			return [
				'monthlyDayNum' => $monthlyDayNum,
				'monthlyMonthNum1' => $monthlyMonthNum1,
				'monthlyWeekDayNum' => null,
				'monthlyWeekDay' => null,
				'monthlyMonthNum2' => null,
			];
		}

		static::rejectProvidedFields(
			$props,
			['monthlyDayNum', 'monthlyMonthNum1'],
			'Only monthlyWeekDayNum, monthlyWeekDay, and monthlyMonthNum2 can be used with monthly week-day recurrence.',
		);

		$monthlyWeekDayNum = static::mapInteger($props, 'monthlyWeekDayNum') ?? 0;
		$monthlyWeekDay = static::mapInteger($props, 'monthlyWeekDay') ?? 0;
		$monthlyMonthNum2 = static::mapInteger($props, 'monthlyMonthNum2') ?? 1;
		static::validateRange($monthlyWeekDayNum, 0, self::MONTHLY_WEEK_DAY_NUM_MAX, 'monthlyWeekDayNum');
		static::validateRange($monthlyWeekDay, 0, self::MONTHLY_WEEK_DAY_MAX, 'monthlyWeekDay');
		static::validateMinimum($monthlyMonthNum2, 1, 'monthlyMonthNum2');

		return [
			'monthlyDayNum' => null,
			'monthlyMonthNum1' => null,
			'monthlyWeekDayNum' => $monthlyWeekDayNum,
			'monthlyWeekDay' => $monthlyWeekDay,
			'monthlyMonthNum2' => $monthlyMonthNum2,
		];
	}

	/**
	 * @return array{
	 *     yearlyDayNum: ?int,
	 *     yearlyMonth1: ?int,
	 *     yearlyWeekDayNum: ?int,
	 *     yearlyWeekDay: ?int,
	 *     yearlyMonth2: ?int
	 * }
	 */
	private static function mapYearlyFields(array $props, Period $period, YearlyType $yearlyType): array
	{
		if ($period !== Period::Yearly)
		{
			return [
				'yearlyDayNum' => static::mapInteger($props, 'yearlyDayNum') ?? 1,
				'yearlyMonth1' => static::mapInteger($props, 'yearlyMonth1') ?? 0,
				'yearlyWeekDayNum' => static::mapInteger($props, 'yearlyWeekDayNum') ?? 0,
				'yearlyWeekDay' => static::mapInteger($props, 'yearlyWeekDay') ?? 0,
				'yearlyMonth2' => static::mapInteger($props, 'yearlyMonth2') ?? 0,
			];
		}

		if ($yearlyType === YearlyType::MonthDay)
		{
			static::rejectProvidedFields(
				$props,
				['yearlyWeekDayNum', 'yearlyWeekDay', 'yearlyMonth2'],
				'Only yearlyDayNum and yearlyMonth1 can be used with yearly month-day recurrence.',
			);

			$yearlyDayNum = static::mapInteger($props, 'yearlyDayNum') ?? 1;
			$yearlyMonth1 = static::mapInteger($props, 'yearlyMonth1') ?? 0;
			static::validateRange($yearlyDayNum, 1, self::YEARLY_DAY_MAX, 'yearlyDayNum');
			static::validateRange($yearlyMonth1, 0, self::YEARLY_MONTH_MAX, 'yearlyMonth1');

			return [
				'yearlyDayNum' => $yearlyDayNum,
				'yearlyMonth1' => $yearlyMonth1,
				'yearlyWeekDayNum' => null,
				'yearlyWeekDay' => null,
				'yearlyMonth2' => null,
			];
		}

		static::rejectProvidedFields(
			$props,
			['yearlyDayNum', 'yearlyMonth1'],
			'Only yearlyWeekDayNum, yearlyWeekDay, and yearlyMonth2 can be used with yearly week-day recurrence.',
		);

		$yearlyWeekDayNum = static::mapInteger($props, 'yearlyWeekDayNum') ?? 0;
		$yearlyWeekDay = static::mapInteger($props, 'yearlyWeekDay') ?? 0;
		$yearlyMonth2 = static::mapInteger($props, 'yearlyMonth2') ?? 0;
		static::validateRange($yearlyWeekDayNum, 0, self::YEARLY_WEEK_DAY_NUM_MAX, 'yearlyWeekDayNum');
		static::validateRange($yearlyWeekDay, 0, self::YEARLY_WEEK_DAY_MAX, 'yearlyWeekDay');
		static::validateRange($yearlyMonth2, 0, self::YEARLY_MONTH_MAX, 'yearlyMonth2');

		return [
			'yearlyDayNum' => null,
			'yearlyMonth1' => null,
			'yearlyWeekDayNum' => $yearlyWeekDayNum,
			'yearlyWeekDay' => $yearlyWeekDay,
			'yearlyMonth2' => $yearlyMonth2,
		];
	}

	private static function rejectProvidedFields(array $props, array $fieldNames, string $message): void
	{
		foreach ($fieldNames as $fieldName)
		{
			if (array_key_exists($fieldName, $props))
			{
				throw new DtoValidationException($message);
			}
		}
	}

	private static function validateRange(int $value, int $minimum, int $maximum, string $fieldName): void
	{
		if ($value < $minimum || $value > $maximum)
		{
			throw new DtoValidationException("{$fieldName} must be between {$minimum} and {$maximum}.");
		}
	}

	private static function validateMinimum(int $value, int $minimum, string $fieldName): void
	{
		if ($value < $minimum)
		{
			throw new DtoValidationException("{$fieldName} must be at least {$minimum}.");
		}
	}
}
