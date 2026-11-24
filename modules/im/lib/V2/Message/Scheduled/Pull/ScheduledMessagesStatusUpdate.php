<?php
declare(strict_types = 1);

namespace Bitrix\Im\V2\Message\Scheduled\Pull;

use Bitrix\Im\V2\Message\Scheduled\ScheduledMessage;
use Bitrix\Im\V2\Pull\EventType;

class ScheduledMessagesStatusUpdate extends BaseScheduledMessageEvent
{
	/**
	 * @param ScheduledMessage[] $scheduledMessages
	 */
	public function __construct(
		private readonly array $scheduledMessages,
		int $authorId,
	)
	{
		parent::__construct($authorId);
	}

	protected function getType(): EventType
	{
		return EventType::ScheduledMessagesStatusUpdate;
	}

	protected function getBasePullParamsInternal(): array
	{
		return [
			'scheduledMessages' => array_map(
				fn(ScheduledMessage $scheduledMessage): array => [
					'id' => $scheduledMessage->getId(),
					'chatId' => $scheduledMessage->getChatId(),
					'status' => $scheduledMessage->getStatus(),
				],
				$this->scheduledMessages,
			),
		];
	}
}
