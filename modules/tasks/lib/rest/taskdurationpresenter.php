<?php

namespace Bitrix\Tasks\Rest;

use Bitrix\Main\Type;
use Bitrix\Tasks\Internals\Task\TimeUnitType;
use Bitrix\Tasks\Util\Calendar;
use Bitrix\Tasks\Util\Type\DateTime;
use Throwable;

final class TaskDurationPresenter
{
	public const DURATION_PLAN_SECONDS = 'DURATION_PLAN_SECONDS';
	public const DURATION_TYPE_ALL = 'DURATION_TYPE_ALL';

	private const SECONDS_IN_HOUR = 3600;
	private const SECONDS_IN_DAY = 86400;

	public function __construct(
		private readonly Calendar $calendar,
	)
	{
	}

	public function present(
		array $task,
		bool $includeDurationPlan,
		bool $includeDurationType,
		array $temporaryFields = [],
	): array
	{
		$durationType = (string)($task[self::DURATION_TYPE_ALL] ?? $task['DURATION_TYPE'] ?? '');
		// Legacy REST: minutes are exposed as hours — add/update do not convert minutes back to seconds.
		$publicDurationType = $durationType === TimeUnitType::MINUTE
			? TimeUnitType::HOUR
			: $durationType;

		if ($includeDurationPlan && array_key_exists(self::DURATION_PLAN_SECONDS, $task))
		{
			if ($task[self::DURATION_PLAN_SECONDS] === null)
			{
				$task['DURATION_PLAN'] = null;
			}
			else
			{
				$task['DURATION_PLAN'] = $this->formatDurationPlan(
					(int)$task[self::DURATION_PLAN_SECONDS],
					$durationType,
					$task,
				);
			}
		}

		if ($includeDurationType)
		{
			$task['DURATION_TYPE'] = $publicDurationType;
		}

		unset($task[self::DURATION_PLAN_SECONDS], $task[self::DURATION_TYPE_ALL]);
		foreach ($temporaryFields as $field)
		{
			unset($task[$field]);
		}

		return $task;
	}

	private function formatDurationPlan(int $duration, string $durationType, array $task): int
	{
		if ($duration === 0)
		{
			return 0;
		}

		return match ($durationType)
		{
			TimeUnitType::MINUTE, TimeUnitType::HOUR => (int)round($duration / self::SECONDS_IN_HOUR),
			TimeUnitType::DAY, '' => $this->formatDays($duration, $task),
			default => (int)($task['DURATION_PLAN'] ?? $duration),
		};
	}

	private function formatDays(int $duration, array $task): int
	{
		if (($task['MATCH_WORK_TIME'] ?? 'N') !== 'Y')
		{
			return (int)round($duration / self::SECONDS_IN_DAY);
		}

		$startDate = $this->createDateTime($task['START_DATE_PLAN'] ?? null);
		$endDate = $this->createDateTime($task['END_DATE_PLAN'] ?? null);
		$workDate = $startDate ?? $endDate;
		if ($workDate === null)
		{
			return (int)round($duration / self::SECONDS_IN_DAY);
		}

		$workDayDuration = $this->getWorkDayDuration($workDate);
		if ($workDayDuration <= 0)
		{
			return (int)round($duration / self::SECONDS_IN_DAY);
		}

		return (int)round($duration / $workDayDuration);
	}

	private function getWorkDayDuration(DateTime $date): int
	{
		$workDate = $this->calendar->getClosestWorkTime($date);
		$duration = 0;

		foreach ($this->calendar->getWorkHours($workDate) as $interval)
		{
			$duration += $interval['endDate']->getTimestamp() - $interval['startDate']->getTimestamp();
		}

		return $duration;
	}

	private function createDateTime(mixed $value): ?DateTime
	{
		if ($value instanceof DateTime)
		{
			return clone $value;
		}

		if ($value instanceof Type\DateTime)
		{
			return DateTime::createFromUserTimeGmt($value->toString());
		}

		if (!is_string($value) || trim($value) === '')
		{
			return null;
		}

		try
		{
			return DateTime::createFromUserTimeGmt($value);
		}
		catch (Throwable)
		{
			return null;
		}
	}
}
