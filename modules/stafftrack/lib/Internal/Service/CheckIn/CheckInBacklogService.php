<?php

namespace Bitrix\StaffTrack\Internal\Service\CheckIn;

use Bitrix\Main\Type\DateTime;
use Bitrix\StaffTrack\Feature;
use Bitrix\StaffTrack\Internal\Repository\CheckInRepository;

class CheckInBacklogService
{
	/**
	 * How long a raw row is left alone before it is folded into the route of its day. Must stay above
	 * PointAcceptanceService::POINT_ACCEPT_PAST_SEC: deduplication and the daily quota read the raw rows,
	 * so folding a row while the window of its point is still open takes them down with it.
	 */
	public const AGGREGATION_DELAY_SEC = 172800;

	/**
	 * Above this many rows a day is folded in chunks instead of in one piece. The bound sits above the
	 * reachable day - about 8000 automatic rows, as the quota of 4000 counts local days while the fold
	 * groups by server ones, which cover two of them, plus the manual check-ins, which have no quota -
	 * because a chunked day costs 16.5 times as much to fold.
	 */
	public const MAX_DAY_ROWS = 15000;

	/**
	 * The same bound for a caller pushing the backlog along from a hit of the portal. A day below it is read
	 * and rewritten in one piece that no limit can cut, so the bar is the size of a fold that holds a worker
	 * undivided; above it the day goes in chunks and REQUEST_TIME_LIMIT starts working.
	 *
	 * Set above the heavy day that really happens rather than inside it: a shift an employee never closes
	 * the app over emits 1723-1852 points, and a server day covers two local ones. At a bar of 2000 the
	 * chunked branch would become the ordinary path for active employees, moving cost from one rare request
	 * onto every start of a working day; at twice the measured day it stays the exception it is meant to be,
	 * while the indivisible piece is still four times smaller than MAX_DAY_ROWS.
	 */
	public const REQUEST_MAX_DAY_ROWS = 4000;

	/**
	 * Rows of the stale window read in one lookup of the employees to work on. It bounds rows and not
	 * employees: the window is walked by a keyset over (DATE_CREATE, USER_ID), and how many owners a
	 * batch of rows names depends on how the tail is spread over them.
	 */
	private const STALE_ROW_BATCH_LIMIT = 500;
	private const CHECK_IN_BATCH_LIMIT = 500;

	/** The whole run of the agent, for which folding the backlog is the work and not an aside. */
	private const EXECUTION_TIME_LIMIT = 120;

	/**
	 * The share of a hit of the portal a caller pushing the backlog along may take. The one such caller,
	 * {@see \Bitrix\StaffTrack\Integration\Timeman\WorkDayService::onAfterTmDayStart()}, runs behind the
	 * answer of its request, so what the limit protects is a worker and not the wait of a person - which is
	 * why it stays this far below the limit of an agent run all the same.
	 *
	 * It bounds the loop of chunks and nothing else - a day folded whole is one indivisible read and
	 * rewrite - which is why such a caller sends a day above REQUEST_MAX_DAY_ROWS into that loop. What it
	 * does not reach is left to the agent.
	 */
	public const REQUEST_TIME_LIMIT = 3;

	private CheckInRepository $repository;
	private CheckInAggregator $aggregator;

	public function __construct(?CheckInRepository $repository = null, ?CheckInAggregator $aggregator = null)
	{
		$this->repository = $repository ?? new CheckInRepository();
		$this->aggregator = $aggregator ?? new CheckInAggregator();
	}

	/**
	 * The window of stale rows is walked by a keyset rather than by repeating the same selection: work over
	 * an employee does not always empty their part of the window (saveRoute() swallows a Throwable), so the
	 * same selection would keep offering them. The keyset names an employee once per row, hence seenUserIds.
	 *
	 * @return bool True if there are still unprocessed users (caller should reschedule).
	 */
	public function aggregateStaleCheckIns(): bool
	{
		if (!Feature::isGeoEncryptionAvailable())
		{
			return false;
		}

		$startTime = time();
		$cursor = [];
		$seenUserIds = [];

		while (true)
		{
			if ($this->isTimeExceeded($startTime, self::EXECUTION_TIME_LIMIT))
			{
				return true;
			}

			$batch = $this->repository->findUsersWithStaleCheckIns(
				$this->threshold(),
				$cursor,
				self::STALE_ROW_BATCH_LIMIT,
			);

			if (empty($batch['userIds']))
			{
				return false;
			}

			$cursor = $batch['cursor'];

			foreach ($batch['userIds'] as $userId)
			{
				if ($this->isTimeExceeded($startTime, self::EXECUTION_TIME_LIMIT))
				{
					return true;
				}

				if (isset($seenUserIds[$userId]))
				{
					continue;
				}
				$seenUserIds[$userId] = true;

				// Without maxDays the only reason to give up on an employee is the time limit.
				if ($this->aggregateUserStaleCheckIns($userId, $startTime))
				{
					return true;
				}
			}
		}
	}

	/**
	 * Folds the days of one employee, oldest first, one day per touch of its route row. A day is taken only
	 * once the threshold has passed its last second: folding a partial day means merging the rest in later,
	 * and that merge re-reads, decrypts, re-sorts and rewrites the whole GEO_DATA.
	 *
	 * @param int $userId
	 * @param int $startTime moment the work started, the time limit is counted from it
	 * @param int|null $maxDays days per call, null for the agent, for which such work is the normal one
	 * @param int|null $timeLimit seconds the call may take, null for the limit of an agent run; a limit
	 *        given here also lowers the bar above which a day is folded in chunks rather than whole
	 * @return bool True if the work on the employee is not finished: the time ran out or maxDays was hit.
	 */
	public function aggregateUserStaleCheckIns(
		int $userId,
		int $startTime,
		?int $maxDays = null,
		?int $timeLimit = null,
	): bool
	{
		if (!Feature::isGeoEncryptionAvailable())
		{
			return false;
		}

		$threshold = $this->threshold();
		$limit = $timeLimit ?? self::EXECUTION_TIME_LIMIT;
		// A caller that carries a limit of its own is a request, and a request cannot afford the one piece
		// of work no limit cuts: the bar above which a day goes in chunks is lower for it.
		$maxDayRows = $timeLimit === null ? self::MAX_DAY_ROWS : self::REQUEST_MAX_DAY_ROWS;
		$previousDay = null;
		$daysDone = 0;

		while (true)
		{
			if ($this->isTimeExceeded($startTime, $limit))
			{
				return true;
			}

			if ($maxDays !== null && $daysDone >= $maxDays)
			{
				return true;
			}

			$day = $this->repository->findOldestFullyStaleDay($userId, $threshold);

			if ($day === null)
			{
				return false;
			}

			// saveRoute() swallows a Throwable and leaves the raw rows in place, so a day that failed comes
			// back on the next iteration and would be read again until the time limit burns out.
			if ($day === $previousDay)
			{
				$this->logFailure("CheckInBacklogService: day {$day} of user {$userId} was not aggregated");

				return false;
			}
			$previousDay = $day;

			$dayRows = $this->repository->countCheckInRowsOfDay($userId, $day);

			// Only a day past the bound of the agent is worth a line: the request path sends the ordinary
			// heavy day into chunks as well, and journalling those would be a line per work day start.
			if ($dayRows > self::MAX_DAY_ROWS)
			{
				$this->logFailure(
					"CheckInBacklogService: day {$day} of user {$userId} holds {$dayRows} rows, folding it in chunks"
				);
			}

			if ($dayRows > $maxDayRows)
			{
				if ($this->aggregateDayInChunks($userId, $day, $startTime, $limit))
				{
					return true;
				}
			}
			else
			{
				$this->aggregator->aggregate($userId, $this->repository->getCheckInRowsOfDay($userId, $day));
			}

			$daysDone++;
		}
	}

	/**
	 * A day too big to be held in memory at once, folded in chunks of CHECK_IN_BATCH_LIMIT rows, each
	 * of them a full rewrite of the route row. Both guards of the outer
	 * loops are set up here anew - neither of them reaches inside a single day.
	 *
	 * @return bool True if the time ran out; the day is then continued by the next run.
	 */
	private function aggregateDayInChunks(int $userId, string $day, int $startTime, int $timeLimit): bool
	{
		$previousChunk = null;

		while (true)
		{
			if ($this->isTimeExceeded($startTime, $timeLimit))
			{
				return true;
			}

			$rows = $this->repository->getCheckInRowsOfDay($userId, $day, self::CHECK_IN_BATCH_LIMIT);

			if (empty($rows))
			{
				return false;
			}

			// Compared by the rows themselves, not by the highest id: chunks go by DATE_CREATE, and a point
			// written after the fact carries a smaller time with a larger id. The day takes no new rows, so
			// an identical chunk means the aggregation deleted none of them.
			$chunk = array_map('intval', array_column($rows, 'ID'));
			sort($chunk);

			if ($chunk === $previousChunk)
			{
				$this->logFailure(
					"CheckInBacklogService: chunk of day {$day} of user {$userId} from id {$chunk[0]}"
					. ' was not aggregated'
				);

				return false;
			}
			$previousChunk = $chunk;

			$this->aggregator->aggregate($userId, $rows);
		}
	}

	private function threshold(): DateTime
	{
		return DateTime::createFromTimestamp(time() - self::AGGREGATION_DELAY_SEC);
	}

	/**
	 * Protected because both the limit and the journal are surroundings of the service rather than its
	 * subject, and a test has no other way to reach either.
	 */
	protected function isTimeExceeded(int $startTime, int $timeLimit): bool
	{
		return (time() - $startTime) >= $timeLimit;
	}

	protected function logFailure(string $message): void
	{
		AddMessage2Log($message, 'stafftrack');
	}
}
