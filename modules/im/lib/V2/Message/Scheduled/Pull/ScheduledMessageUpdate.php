<?php
declare(strict_types = 1);

namespace Bitrix\Im\V2\Message\Scheduled\Pull;

use Bitrix\Im\V2\Message\Scheduled\ScheduledMessage;
use Bitrix\Im\V2\Pull\EventType;

class ScheduledMessageUpdate extends BaseScheduledMessageEvent
{
	public function __construct(
		private readonly ScheduledMessage $scheduledMessage,
		int $authorId
	)
	{
		parent::__construct($authorId);
	}

	protected function getType(): EventType
	{
		return EventType::ScheduledMessageUpdate;
	}

	protected function getBasePullParamsInternal(): array
	{
		return [
			'scheduledMessage' => $this->scheduledMessage->toRestFormat(),
		];
	}
}
