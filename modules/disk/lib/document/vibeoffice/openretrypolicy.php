<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice;

/**
 * Decisions of the `open` retry cycle (ALG-RETRY): whether a platform refusal is temporary, how long
 * to wait and whether the remaining budget still holds one more attempt.
 *
 * The policy knows neither HTTP nor the document session: it takes the platform code, the delay the
 * platform asked for and plain wall-clock readings, and answers with a verdict. Time is owned by the
 * caller - the policy never reads a clock and never sleeps.
 */
final class OpenRetryPolicy
{
	public const DEFAULT_BUDGET_SEC = 15;
	public const DEFAULT_MAX_ATTEMPTS = 3;
	public const DEFAULT_WORST_ANSWER_SEC = 10;
	public const DEFAULT_LONG_ATTEMPT_SEC = 2;
	public const DEFAULT_MAX_PAUSE_SEC = 5;

	/**
	 * The temporary class of ALG-RETRY: the only refusals the platform marks with `Retry-After`.
	 * `doc_full`, `no_grant`, `429` and a transport failure are deliberately absent.
	 *
	 * The single source of the class: the cycle repeats these codes and the controller answers them
	 * with the "still saving" screen, so a code named in one place and missing in the other would
	 * split the repeat from the screen the user ends up on.
	 */
	public const TEMPORARY_PLATFORM_CODES = [
		'saving_in_progress',
		'session_closing',
		'open_in_progress',
		'failover_in_progress',
	];

	/**
	 * The way in to the open is repeated too, and on one refusal only: the named lock of the document,
	 * which a save running in a neighbouring tab holds for as long as it writes the version. The wait
	 * of the lock is itself the price of an attempt, so the pause only breaks the coincidence of two
	 * tabs asking again at the same moment.
	 *
	 * Not a single one of these attempts reaches the platform - the reservation is taken before the
	 * cycle of `open` starts - so the cap of ALG-RETRY on the requests of an open is untouched.
	 */
	public const BEGIN_OPEN_ATTEMPTS = 3;
	public const BEGIN_OPEN_PAUSE_MICROSECONDS = 400_000;

	/** The timeout of the named lock of the document: what one more attempt at the registry may cost. */
	private const BEGIN_OPEN_WAIT_SEC = 5;

	private const PAUSE_JITTER_RATIO = 0.2;
	private const MICROSECONDS_IN_SECOND = 1_000_000;

	public function __construct(
		private readonly int $budgetSec = self::DEFAULT_BUDGET_SEC,
		private readonly int $maxAttempts = self::DEFAULT_MAX_ATTEMPTS,
		private readonly int $worstAnswerSec = self::DEFAULT_WORST_ANSWER_SEC,
		private readonly int $longAttemptSec = self::DEFAULT_LONG_ATTEMPT_SEC,
		private readonly int $maxPauseSec = self::DEFAULT_MAX_PAUSE_SEC,
	)
	{
	}

	public static function createFromConfiguration(Configuration $configuration): self
	{
		return new self(
			$configuration->getOpenBudgetSeconds(),
			$configuration->getOpenMaxAttempts(),
			$configuration->getOpenWorstAnswerSeconds(),
			$configuration->getOpenLongAttemptSeconds(),
			$configuration->getOpenMaxPauseSeconds(),
		);
	}

	/**
	 * The policy of an open that must not be repeated: one attempt, no pause and no budget of its own.
	 * ALG-RETRY allows no attempt past a change of the session type, and a second full cycle after the
	 * downgrade to a view would double the declared server render time.
	 */
	public static function singleAttempt(): self
	{
		return new self(budgetSec: 0, maxAttempts: 1);
	}

	public function getBudgetSeconds(): int
	{
		return $this->budgetSec;
	}

	/**
	 * Whether the cap still allows an attempt after the given number of them has been made.
	 */
	public function hasAttemptsLeft(int $madeAttempts): bool
	{
		return $madeAttempts < $this->maxAttempts;
	}

	public function isRetryablePlatformCode(?string $platformCode): bool
	{
		return self::isTemporaryPlatformCode($platformCode);
	}

	public static function isTemporaryPlatformCode(?string $platformCode): bool
	{
		return $platformCode !== null && in_array($platformCode, self::TEMPORARY_PLATFORM_CODES, true);
	}

	/**
	 * A refusal the platform spent its own waiting on: repeating it would do exactly the same again.
	 */
	public function isLongAttempt(float $attemptDurationSec): bool
	{
		return $attemptDurationSec >= (float)$this->longAttemptSec;
	}

	public function resolvePauseSeconds(?int $retryAfterSec): ?int
	{
		if ($retryAfterSec === null || $retryAfterSec <= 0 || $retryAfterSec > $this->maxPauseSec)
		{
			return null;
		}

		return $retryAfterSec;
	}

	public function scatterPauseMicroseconds(int $pauseSec): int
	{
		$pauseMicroseconds = $pauseSec * self::MICROSECONDS_IN_SECOND;
		if ($pauseMicroseconds <= 0)
		{
			return 0;
		}

		$maximum = min(
			$pauseMicroseconds + (int)round($pauseMicroseconds * self::PAUSE_JITTER_RATIO),
			$this->maxPauseSec * self::MICROSECONDS_IN_SECOND,
		);

		return random_int($pauseMicroseconds, $maximum);
	}

	/**
	 * Whether the remaining budget holds the pause plus the worst platform answer. The deadline
	 * limits the start of an attempt, never the completion of one already running.
	 */
	public function fitsIntoBudget(float $now, float $deadline, float $pauseSec): bool
	{
		return ($now + $pauseSec + $this->worstAnswerSec) <= $deadline;
	}

	/**
	 * Whether the cap still allows one more attempt at the reservation of the open.
	 */
	public function hasBeginOpenAttemptsLeft(int $madeAttempts): bool
	{
		return $madeAttempts < self::BEGIN_OPEN_ATTEMPTS;
	}

	/**
	 * Whether the budget of the open still holds the pause plus one more wait of the named lock. The
	 * deadline is the one of the whole open, so a repeat of the reservation never buys itself time at
	 * the expense of the cycle that follows it; a policy of a single attempt keeps no budget at all
	 * and therefore repeats nothing.
	 */
	public function fitsBeginOpenIntoBudget(float $now, float $deadline): bool
	{
		$pauseSec = self::BEGIN_OPEN_PAUSE_MICROSECONDS / self::MICROSECONDS_IN_SECOND;

		return ($now + $pauseSec + self::BEGIN_OPEN_WAIT_SEC) <= $deadline;
	}
}
