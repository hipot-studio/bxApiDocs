<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Integration\Calendar\Dto;

use Bitrix\Main\Type\DateTime;

class GroupMeetingDto
{
	public readonly DateTime $endDate;

	public function __construct(
		public readonly int $meetingId,
		public readonly string $name,
		public readonly DateTime $date,
		public readonly int $chatId,
		?DateTime $endDate = null,
		public readonly bool $isAllDay = false,
		public readonly bool $isRecurring = false,
		public readonly ?DateTime $previousOccurrenceEndDate = null,
	)
	{
		$this->endDate = $endDate ?? $date;
	}
}
