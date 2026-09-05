<?php

namespace Bitrix\BIConnector\Integration\Superset\Recovery;

use Bitrix\Main\Config\Option;

/**
 * State of the recovery series for a portal stuck in ERROR: when the series started and how many
 * automatic attempts have already been made. Also owns the attempt schedule arithmetic.
 *
 * Presence of the state means the series for the current incident is either running or already
 * exhausted. An exhausted series is not cleared, otherwise the next user visit would start
 * a fresh 48-hour series. Only reset points (READY, terminal statuses, license change) clear it.
 */
final class StatusRecoveryWindow
{
	private const MODULE_ID = 'biconnector';

	private const STARTED_AT_OPTION = '~superset_recovery_window_started';
	private const ATTEMPT_OPTION = '~superset_recovery_attempt';

	/**
	 * Attempt marks in seconds from the series start: 30m, 1h, 2h, 4h, 8h, 16h.
	 */
	private const ATTEMPT_OFFSETS = [1800, 3600, 7200, 14400, 28800, 57600];

	/**
	 * Hard limit of the series (48h). The final attempt is pinned to this mark.
	 */
	private const FINAL_OFFSET = 172800;

	/**
	 * Lower bound for a scheduled delay: protects from a tight retry loop when cron lags behind.
	 */
	private const MIN_DELAY = 60;

	public function open(int $now): void
	{
		Option::set(self::MODULE_ID, self::STARTED_AT_OPTION, $now);
		Option::set(self::MODULE_ID, self::ATTEMPT_OPTION, 0);
	}

	public function clear(): void
	{
		Option::delete(self::MODULE_ID, ['name' => self::STARTED_AT_OPTION]);
		Option::delete(self::MODULE_ID, ['name' => self::ATTEMPT_OPTION]);
	}

	/**
	 * Whether a series for the current incident exists: running or already exhausted.
	 */
	public function isTracked(): bool
	{
		return $this->getStartedAt() > 0;
	}

	public function getStartedAt(): int
	{
		return (int)Option::get(self::MODULE_ID, self::STARTED_AT_OPTION, 0);
	}

	public function getAttempt(): int
	{
		return (int)Option::get(self::MODULE_ID, self::ATTEMPT_OPTION, 0);
	}

	public function registerAttempt(int $attemptNumber): void
	{
		Option::set(self::MODULE_ID, self::ATTEMPT_OPTION, $attemptNumber);
	}

	/**
	 * Whether the hard limit of the series has passed.
	 */
	public function isExpired(int $now): bool
	{
		return $this->isTracked() && $now > $this->getStartedAt() + self::FINAL_OFFSET;
	}

	/**
	 * Resolves the attempt that is due right now: the number of the last mark already reached.
	 * The agent always wakes up at or after its own mark, so the due attempt is the one whose mark
	 * is in the past, not the next one in the future.
	 *
	 * Marks passed during a cron outage do not produce a burst: a single run performs one attempt
	 * and the number jumps straight to the reached mark.
	 *
	 * @param int $now Current unix timestamp.
	 *
	 * @return int|null Null when every reached mark is already spent, including a run before the
	 *                  first mark and an exhausted series.
	 */
	public function resolveDueAttempt(int $now): ?int
	{
		if (!$this->isTracked())
		{
			return null;
		}

		$startedAt = $this->getStartedAt();
		$reachedMarks = 0;
		foreach (self::ATTEMPT_OFFSETS as $offset)
		{
			if ($startedAt + $offset > $now)
			{
				break;
			}

			$reachedMarks++;
		}

		if ($startedAt + self::FINAL_OFFSET <= $now)
		{
			$reachedMarks = count(self::ATTEMPT_OFFSETS) + 1;
		}

		return $reachedMarks > max($this->getAttempt(), 0) ? $reachedMarks : null;
	}

	/**
	 * Resolves the attempt to schedule next: its number and the delay in seconds. Used for planning
	 * only — the number of the attempt to perform right now comes from resolveDueAttempt().
	 * Marks already in the past are skipped, so a cron outage does not produce a burst of attempts.
	 *
	 * @param int $now Current unix timestamp.
	 *
	 * @return array{number: int, delay: int}|null Null when the series is exhausted or absent.
	 */
	public function resolveNextAttempt(int $now): ?array
	{
		if (!$this->isTracked())
		{
			return null;
		}

		$startedAt = $this->getStartedAt();
		$attempt = max($this->getAttempt(), 0);
		$offsetsCount = count(self::ATTEMPT_OFFSETS);

		for ($index = $attempt; $index < $offsetsCount; $index++)
		{
			$attemptAt = $startedAt + self::ATTEMPT_OFFSETS[$index];
			if ($attemptAt > $now)
			{
				return [
					'number' => $index + 1,
					'delay' => max($attemptAt - $now, self::MIN_DELAY),
				];
			}
		}

		$finalAt = $startedAt + self::FINAL_OFFSET;
		if ($attempt <= $offsetsCount && $finalAt > $now)
		{
			return [
				'number' => $offsetsCount + 1,
				'delay' => max($finalAt - $now, self::MIN_DELAY),
			];
		}

		return null;
	}
}
