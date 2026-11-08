<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Access\Activity;

/**
 * The answer to an access question: allowed, denied, or no opinion at all.
 *
 * Three outcomes rather than a boolean, because the party asked is not always the party deciding. A
 * provider of activities may allow, may forbid, and may leave the question to the core - and those are
 * three different answers, not two: "no opinion" must not collapse into "denied", or the rules of the core
 * would never be reached.
 *
 * {@see ActivityAccess::decide()} answers with {@see self::Allow} or {@see self::Deny} only: whoever asks
 * about an activity gets an answer.
 */
enum AccessDecision
{
	case Allow;
	case Deny;
	case NoOpinion;

	/** Whether this is an answer rather than an abstention. */
	public function hasOpinion(): bool
	{
		return $this !== self::NoOpinion;
	}

	public function isAllowed(): bool
	{
		return $this === self::Allow;
	}
}
