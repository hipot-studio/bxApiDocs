<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\DateNavigation;

use Bitrix\Main\Type\DateTime;

/** A [from, to) stretch of server time the shift to the time of the user stays constant over. */
class TimeSegment
{
	public function __construct(
		private readonly DateTime $from,
		private readonly DateTime $to,
		private readonly int $userTimeOffset,
	){}

	public function getFrom(): DateTime
	{
		return $this->from;
	}

	public function getTo(): DateTime
	{
		return $this->to;
	}

	public function getUserTimeOffset(): int
	{
		return $this->userTimeOffset;
	}
}
