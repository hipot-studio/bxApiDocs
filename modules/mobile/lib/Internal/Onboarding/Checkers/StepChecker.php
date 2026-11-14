<?php

namespace Bitrix\Mobile\Internal\Onboarding\Checkers;

use Bitrix\Main\Type\DateTime;

interface StepChecker
{
	/**
	 * True when the step is completed by the user.
	 *
	 * $sinceDate is the lower bound of the facts that count - the portal creation date, passed
	 * from outside, never computed by the checker. An implementation applies it to the facts
	 * that carry a date of their own; a fact without one (the web login, for instance,
	 * is a flag rather than a dated record) counts regardless of the threshold.
	 *
	 * The bound does NOT separate demo content from work of the user: it is the creation date of
	 * the portal with a granularity of a day, so anything stamped on that first day - the demo
	 * records among it - is on the counting side of it. Whether that matters depends on whom the
	 * demo records are attributed to, which is still an open question of the campaign.
	 */
	public function isCompleted(int $userId, DateTime $sinceDate): bool;
}
