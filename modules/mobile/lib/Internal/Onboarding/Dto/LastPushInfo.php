<?php

namespace Bitrix\Mobile\Internal\Onboarding\Dto;

use Bitrix\Mobile\Internal\Onboarding\PushType;

/**
 * Campaign state of one user (DTO-01), stored as the user option
 * mobile/OnboardingLastPush. Holds bookkeeping only: per-step attempt counters, the
 * first-send date of every step push, the final-push anchor and the final-push flags.
 * Step progress is never stored - it is recomputed by the checkers on every pass.
 *
 * Records written before the activation series carry only type and timestamp; they
 * are read back with empty counters and default flags.
 */
final readonly class LastPushInfo
{
	/**
	 * @param string $type push type of the last record; a string rather than PushType,
	 *                     because a record written before the activation series may
	 *                     carry a value no enum case matches
	 * @param array<string, int> $stepAttempts keyed by PushType->value
	 * @param array<string, int> $stepFirstSentAt keyed by PushType->value
	 */
	public function __construct(
		public string $type,
		public int $timestamp,
		public array $stepAttempts = [],
		public ?int $lastSeriesPushDate = null,
		public bool $meetupSent = false,
		public ?int $meetupPushDate = null,
		public bool $helpSent = false,
		public array $stepFirstSentAt = [],
	) {}

	public function getAttempts(PushType $pushType): int
	{
		return (int)($this->stepAttempts[$pushType->value] ?? 0);
	}

	/**
	 * How many pushes of the series have gone out. Every send bumps the counter of exactly one
	 * step, so the sum of the counters is the length of the series so far - which is what the
	 * rhythm needs to tell the first repeat from the ones after it.
	 */
	public function getSeriesSendCount(): int
	{
		return (int)array_sum($this->stepAttempts);
	}

	/**
	 * Timestamp of the first send of that push type, null when it has never been sent.
	 */
	public function getFirstSentAt(PushType $pushType): ?int
	{
		$timestamp = $this->stepFirstSentAt[$pushType->value] ?? null;

		return is_numeric($timestamp) ? (int)$timestamp : null;
	}
}
