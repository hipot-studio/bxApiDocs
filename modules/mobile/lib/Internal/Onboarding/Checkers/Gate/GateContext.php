<?php

namespace Bitrix\Mobile\Internal\Onboarding\Checkers\Gate;

use Bitrix\Main\Type\DateTime;

/**
 * Dates a gate may compare facts against: the series anchor (the portal creation date,
 * the same threshold every checker gets) and the date the invite push was first sent
 * to this user.
 *
 * getThreshold() degrades to the anchor while the invite push has not been sent yet -
 * a user who invited a colleague before the series reached him must not be judged
 * against a date that does not exist.
 */
final readonly class GateContext
{
	public function __construct(
		public DateTime $seriesAnchor,
		public ?DateTime $invitePushSentAt = null,
	)
	{}

	public function getThreshold(): DateTime
	{
		return clone ($this->invitePushSentAt ?? $this->seriesAnchor);
	}
}
