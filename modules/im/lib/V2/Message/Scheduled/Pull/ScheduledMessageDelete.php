<?php
declare(strict_types = 1);

namespace Bitrix\Im\V2\Message\Scheduled\Pull;

use Bitrix\Im\V2\Pull\EventType;

class ScheduledMessageDelete extends BaseScheduledMessageEvent
{
	public function __construct(
		private readonly int $scheduledMessageId,
		int $authorId
	)
	{
		parent::__construct($authorId);
	}

	protected function getType(): EventType
	{
		return EventType::ScheduledMessageDelete;
	}

	protected function getBasePullParamsInternal(): array
	{
		return [
			'scheduledMessageId' => $this->scheduledMessageId,
		];
	}
}
