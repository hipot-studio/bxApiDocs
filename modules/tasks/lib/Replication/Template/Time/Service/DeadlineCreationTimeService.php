<?php

declare(strict_types=1);

namespace Bitrix\Tasks\Replication\Template\Time\Service;

use Bitrix\Tasks\Replication\Template\AbstractParameter;
use Bitrix\Tasks\Replication\Template\Enum\OffsetDayType;
use Bitrix\Tasks\Replication\Template\Option\Options;
use Bitrix\Tasks\Replication\Template\Time\Enum\Period;
use Bitrix\Tasks\Replication\Template\Time\Factory\ExecutionTimeFactory;
use Bitrix\Tasks\UI;
use Bitrix\Tasks\Util\Calendar;
use Bitrix\Tasks\Util\Type\DateTime;

/**
 * Deadline-driven regular task strategy (REPLICATE_TYPE = deadline).
 *
 * The creation date is a backward offset from the schedule of deadlines: the instance
 * is created CREATE_OFFSET days (calendar or workday) before the closest on-pattern
 * deadline. Implements ALG-01.
 *
 * Tail-of-month clamp is built into step 1 (not applied post-hoc): for the monthly
 * by-day-number pattern the requested day is clamped to the last existing day of the
 * TARGET month before the date is assembled, so day 31 in February resolves to the last
 * day of February instead of rolling over into March (as the raw factory would).
 *
 * This is a separate, forward-independent code path: it does not reuse DEADLINE_OFFSET
 * (the forward "start + offset" semantics of the schedule strategy).
 */
final class DeadlineCreationTimeService
{
	private const SECONDS_IN_DAY = 86400;
	private const MONTHS_IN_YEAR = 12;

	public function __construct(private readonly Calendar $calendar = new Calendar())
	{
	}

	/**
	 * @param int $baseExecutionTimeTS server-local base timestamp (already clamped to replication start)
	 * @param int $creatorPreferredTimeTS seconds-of-day of the preferred HH:MM (creator tz applied)
	 * @param int $replicationStartTimeTS server-local start-of-repetition timestamp (0 when unset)
	 * @param AbstractParameter $parameter replicate params (PERIOD, MONTHLY_DAY_NUM, CREATE_OFFSET, OFFSET_DAY_TYPE, ...)
	 */
	public function calculate(
		int $baseExecutionTimeTS,
		int $creatorPreferredTimeTS,
		int $replicationStartTimeTS,
		AbstractParameter $parameter,
	): DeadlineCreationTime
	{
		$params = Options::validate($parameter->getData());

		$createOffset = (int)($params['CREATE_OFFSET'] ?? 0);
		$offsetDayType = $this->resolveOffsetDayType($parameter, $params);
		$workdayMode = $offsetDayType === OffsetDayType::Workday;

		$deadlineTS = $this->resolveDeadline($baseExecutionTimeTS, $creatorPreferredTimeTS, $parameter, $params, $workdayMode);
		$creationTS = $this->applyCreationOffset($deadlineTS, $createOffset, $offsetDayType);

		// First period: the creation date must not precede the start of repetition (AC-T-007b).
		while ($replicationStartTimeTS > 0 && $creationTS < $replicationStartTimeTS)
		{
			$nextBaseTS = $deadlineTS + 1;
			$deadlineTS = $this->resolveDeadline($nextBaseTS, $creatorPreferredTimeTS, $parameter, $params, $workdayMode);
			$creationTS = $this->applyCreationOffset($deadlineTS, $createOffset, $offsetDayType);
		}

		return new DeadlineCreationTime($creationTS, $deadlineTS);
	}

	/**
	 * @param null|callable('step'|'jump'|'century', int): void $transitionObserver
	 */
	public function calculateAtOrAfter(
		int $baseExecutionTimeTS,
		int $boundaryTS,
		int $creatorPreferredTimeTS,
		int $replicationStartTimeTS,
		AbstractParameter $parameter,
		?callable $transitionObserver = null,
	): OccurrenceSearchResult
	{
		$deadlineCreationTime = $this->calculate(
			$baseExecutionTimeTS,
			$creatorPreferredTimeTS,
			$replicationStartTimeTS,
			$parameter,
		);
		$this->notifyTransition($transitionObserver, 'step', 1);
		if ($deadlineCreationTime->creationTime >= $boundaryTS)
		{
			return new OccurrenceSearchResult($deadlineCreationTime, 0);
		}

		return $this->getCyclicDeadlineCreationTimeAtOrAfter(
			$deadlineCreationTime,
			$boundaryTS,
			$creatorPreferredTimeTS,
			$replicationStartTimeTS,
			$parameter,
			$transitionObserver,
		);
	}

	private function getCyclicDeadlineCreationTimeAtOrAfter(
		DeadlineCreationTime $deadlineCreationTime,
		int $boundaryTS,
		int $creatorPreferredTimeTS,
		int $replicationStartTimeTS,
		AbstractParameter $parameter,
		?callable $transitionObserver,
	): OccurrenceSearchResult
	{
		$params = Options::validate($parameter->getData());
		$offsetDayType = $this->resolveOffsetDayType($parameter, $params);
		$isDateDependent =
			$params['PERIOD'] === Period::MONTHLY
			|| $params['PERIOD'] === Period::YEARLY
			|| $offsetDayType === OffsetDayType::Workday
		;
		$seen = [];
		$skipped = 0;

		while ($deadlineCreationTime->creationTime < $boundaryTS)
		{
			$state = $isDateDependent
				? ((int)date('Y', $deadlineCreationTime->deadline) % 4)
					. '-'
					. date('m-d-N-L-H:i:s', $deadlineCreationTime->deadline)
					. '|'
					. ((int)date('Y', $deadlineCreationTime->creationTime) % 4)
					. '-'
					. date('m-d-N-L-H:i:s', $deadlineCreationTime->creationTime)
				: date('N-H:i:s', $deadlineCreationTime->deadline)
					. '|'
					. date('N-H:i:s', $deadlineCreationTime->creationTime)
			;
			if (isset($seen[$state]))
			{
				$cycleDays =
					$this->getDayNumber($deadlineCreationTime->deadline)
					- $this->getDayNumber($seen[$state]['occurrence']->deadline)
				;
				$cycleOccurrences = $skipped - $seen[$state]['skipped'];
				if ($cycleDays > 0 && $cycleOccurrences > 0)
				{
					$cycles = $this->getSafeCycleCount(
						$deadlineCreationTime,
						$boundaryTS,
						$cycleDays,
					);
					if ($cycles > 0)
					{
						$deadlineCreationTime = new DeadlineCreationTime(
							$this->shiftLocalDays($deadlineCreationTime->creationTime, $cycles * $cycleDays),
							$this->shiftLocalDays($deadlineCreationTime->deadline, $cycles * $cycleDays),
						);
						$skipped += $cycles * $cycleOccurrences;
						$this->notifyTransition($transitionObserver, 'jump', $cycles * $cycleOccurrences);
						$seen = [];

						continue;
					}
				}
			}
			else
			{
				$seen[$state] = [
					'occurrence' => $deadlineCreationTime,
					'skipped' => $skipped,
				];
			}

			$baseExecutionTimeTS = $deadlineCreationTime->deadline + 1;
			$gregorianExceptionDay = $this->getNextGregorianExceptionDayNumber(
				$deadlineCreationTime->deadline,
			);
			$skipped++;
			$deadlineCreationTime = $this->calculate(
				$baseExecutionTimeTS,
				$creatorPreferredTimeTS,
				$replicationStartTimeTS,
				$parameter,
			);
			$this->notifyTransition($transitionObserver, 'step', 1);
			if ($deadlineCreationTime->deadline <= $baseExecutionTimeTS)
			{
				return new OccurrenceSearchResult(new DeadlineCreationTime(0, 0), $skipped);
			}
			if ($this->getDayNumber($deadlineCreationTime->deadline) >= $gregorianExceptionDay)
			{
				$seen = [];
				$this->notifyTransition($transitionObserver, 'century', 0);
			}
		}

		return new OccurrenceSearchResult($deadlineCreationTime, $skipped);
	}

	private function notifyTransition(?callable $observer, string $type, int $occurrences): void
	{
		if ($observer !== null)
		{
			$observer($type, $occurrences);
		}
	}

	private function getSafeCycleCount(
		DeadlineCreationTime $occurrence,
		int $boundaryTS,
		int $cycleDays,
	): int
	{
		$currentDay = $this->getDayNumber($occurrence->deadline);
		$boundaryDay = $this->getDayNumber($boundaryTS);
		$cycles = intdiv(max(0, $boundaryDay - $this->getDayNumber($occurrence->creationTime)), $cycleDays);
		$exceptionDay = $this->getNextGregorianExceptionDayNumber($occurrence->deadline);
		if ($exceptionDay <= $boundaryDay)
		{
			// The short calendar cycle is valid only until the next omitted century leap day.
			$cycles = min(
				$cycles,
				intdiv(max(0, $exceptionDay - 1 - $currentDay), $cycleDays),
			);
		}

		return $cycles;
	}

	private function getDayNumber(int $timestamp): int
	{
		return intdiv(
			(int)gmmktime(
				0,
				0,
				0,
				(int)date('n', $timestamp),
				(int)date('j', $timestamp),
				(int)date('Y', $timestamp),
			),
			self::SECONDS_IN_DAY,
		);
	}

	private function getNextGregorianExceptionDayNumber(int $timestamp): int
	{
		$year = (int)date('Y', $timestamp);
		$candidate = intdiv($year + 99, 100) * 100;

		while (true)
		{
			if ($candidate % 400 !== 0)
			{
				$exceptionDay = intdiv((int)gmmktime(0, 0, 0, 3, 1, $candidate), self::SECONDS_IN_DAY);
				if ($exceptionDay > $this->getDayNumber($timestamp))
				{
					return $exceptionDay;
				}
			}

			$candidate += 100;
		}
	}

	private function shiftLocalDays(int $timestamp, int $days): int
	{
		$shiftedDay = ($this->getDayNumber($timestamp) + $days) * self::SECONDS_IN_DAY;

		return (int)mktime(
			(int)date('G', $timestamp),
			(int)date('i', $timestamp),
			(int)date('s', $timestamp),
			(int)gmdate('n', $shiftedDay),
			(int)gmdate('j', $shiftedDay),
			(int)gmdate('Y', $shiftedDay),
		);
	}

	/**
	 * Closest on-pattern deadline with the tail-of-month clamp built in (ALG-01, step 1).
	 *
	 * Only the monthly by-day-number pattern can overflow in the raw factory
	 * (strtotime('2024-02-31') === March 2nd); for it the deadline is assembled with the
	 * day clamped to the target month. Every other pattern is delegated to the factory
	 * unchanged, keeping the deadline schedule identical to the schedule strategy.
	 */
	private function getClosestDeadline(
		int $baseExecutionTimeTS,
		int $creatorPreferredTimeTS,
		AbstractParameter $parameter,
		array $params,
	): int
	{
		if ($this->isMonthlyByDay($params))
		{
			return $this->getMonthlyByDayDeadline($baseExecutionTimeTS, $creatorPreferredTimeTS, $params);
		}

		return ExecutionTimeFactory::getNextExecutionTime(
			$baseExecutionTimeTS,
			$creatorPreferredTimeTS,
			$parameter,
		);
	}

	private function isMonthlyByDay(array $params): bool
	{
		return $params['PERIOD'] === Period::MONTHLY && (int)$params['MONTHLY_TYPE'] !== 2;
	}

	/**
	 * Monthly by-day-number deadline, mirroring
	 * {@see ExecutionTimeFactory::getTemplateMonthlyExecutionDate()} (MONTH sub-type) but
	 * assembling the day with an explicit clamp to the target month, so an out-of-range
	 * day (31 in a short month) resolves to the last day of that month (AC-T-007a) instead
	 * of rolling over. For in-range days the result matches the factory bit-for-bit.
	 */
	private function getMonthlyByDayDeadline(int $baseExecutionTimeTS, int $creatorPreferredTimeTS, array $params): int
	{
		$day = $this->getDayNum($params);
		$monthStep = $this->getMonthlyMonthNum($params);

		$year = (int)date('Y', $baseExecutionTimeTS);
		$month = (int)date('n', $baseExecutionTimeTS);

		$date = $this->buildClampedDate($year, $month, $day, $creatorPreferredTimeTS);
		if ($date <= $baseExecutionTimeTS)
		{
			[$year, $month] = $this->addMonths($year, $month, $monthStep);
			$date = $this->buildClampedDate($year, $month, $day, $creatorPreferredTimeTS);
		}

		return $date;
	}

	private function buildClampedDate(int $year, int $month, int $day, int $creatorPreferredTimeTS): int
	{
		$day = min($day, $this->daysInMonth($year, $month));

		return (int)mktime(0, 0, 0, $month, $day, $year) + $creatorPreferredTimeTS;
	}

	private function addMonths(int $year, int $month, int $monthsToAdd): array
	{
		$zeroBased = ($month - 1) + $monthsToAdd;
		$year += intdiv($zeroBased, self::MONTHS_IN_YEAR);
		$month = ($zeroBased % self::MONTHS_IN_YEAR) + 1;

		return [$year, $month];
	}

	/**
	 * On-pattern deadline with the working-day shift applied when the unified flag is on:
	 * a rhythm deadline that lands on a weekend or holiday is moved forward to the closest
	 * working day (keeping the preferred HH:MM), so the instance DEADLINE never falls on a
	 * weekend/holiday. The raw pattern is delegated to {@see getClosestDeadline()}.
	 */
	private function resolveDeadline(
		int $baseExecutionTimeTS,
		int $creatorPreferredTimeTS,
		AbstractParameter $parameter,
		array $params,
		bool $workdayMode,
	): int
	{
		$deadlineTS = $this->getClosestDeadline($baseExecutionTimeTS, $creatorPreferredTimeTS, $parameter, $params);
		if ($workdayMode)
		{
			$deadlineTS = $this->shiftDeadlineToWorkday($deadlineTS, $creatorPreferredTimeTS);
		}

		return $deadlineTS;
	}

	/**
	 * Day-kind for the creation offset. Follows the unified working-days flag (WORKDAY_ONLY);
	 * OFFSET_DAY_TYPE is a legacy fallback only for pre-unification templates that never stored
	 * WORKDAY_ONLY and have not been re-saved yet (checked on the raw, non-defaulted params).
	 */
	private function resolveOffsetDayType(AbstractParameter $parameter, array $params): OffsetDayType
	{
		if (array_key_exists('WORKDAY_ONLY', $parameter->getData()))
		{
			return OffsetDayType::fromWorkdayFlag($params['WORKDAY_ONLY'] ?? null);
		}

		return OffsetDayType::fromValue($params['OFFSET_DAY_TYPE'] ?? null);
	}

	private function shiftDeadlineToWorkday(int $deadlineTS, int $creatorPreferredTimeTS): int
	{
		if ($this->calendar->isWorkday($deadlineTS))
		{
			return $deadlineTS;
		}

		// Calendar works only with UTC datetime objects, mirror ExecutionTimeFactory.
		$dateInst = DateTime::createFromUserTimeGmt(UI::formatDateTime($deadlineTS));
		$closest = $this->calendar->getClosestWorkTime($dateInst)->convertToLocalTime();

		return $this->stripTime($closest->getTimestamp()) + $creatorPreferredTimeTS;
	}

	private function stripTime(int $timestamp): int
	{
		return (int)mktime(0, 0, 0, (int)date('n', $timestamp), (int)date('j', $timestamp), (int)date('Y', $timestamp));
	}

	private function applyCreationOffset(int $deadlineTS, int $createOffset, OffsetDayType $offsetDayType): int
	{
		if ($offsetDayType === OffsetDayType::Workday)
		{
			return $this->calendar->shiftWorkdays($deadlineTS, -$createOffset);
		}

		return $deadlineTS - $createOffset * self::SECONDS_IN_DAY;
	}

	private function daysInMonth(int $year, int $month): int
	{
		return (int)date('t', (int)mktime(0, 0, 0, $month, 1, $year));
	}

	private function getDayNum(array $params): int
	{
		$dayNum = (int)$params['MONTHLY_DAY_NUM'];

		return $dayNum >= 1 ? $dayNum : 1;
	}

	private function getMonthlyMonthNum(array $params): int
	{
		$monthlyMonthNum = (int)$params['MONTHLY_MONTH_NUM_1'];

		return $monthlyMonthNum > 0 ? $monthlyMonthNum : 1;
	}
}
