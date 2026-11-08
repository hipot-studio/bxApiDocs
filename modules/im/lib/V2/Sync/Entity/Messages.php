<?php

namespace Bitrix\Im\V2\Sync\Entity;

use Bitrix\Im\V2\Sync\Entity;
use Bitrix\Im\V2\Sync\Event;

class Messages implements Entity
{
	private array $messageIds = [];
	private array $addedMessageIds = [];
	private array $updatedMessageIds = [];
	private array $completeDeletedMessageIds = [];

	public function add(Event $event): void
	{
		$entityId = $event->entityId;

		if ($event->entityType === Event::UPDATED_MESSAGE_ENTITY)
		{
			$this->messageIds[$entityId] = $entityId;
			$this->updatedMessageIds[$entityId] = $entityId;
			return;
		}

		switch ($event->eventName)
		{
			case Event::COMPLETE_DELETE_EVENT:
				$this->completeDeletedMessageIds[$entityId] = $entityId;
				break;
			case Event::ADD_EVENT:
				$this->messageIds[$entityId] = $entityId;
				$this->addedMessageIds[$entityId] = $entityId;
				break;
			case Event::DELETE_EVENT:
				$this->messageIds[$entityId] = $entityId;
				$this->updatedMessageIds[$entityId] = $entityId;
				break;
		}
	}

	public function getMessageIds(): array
	{
		return $this->messageIds;
	}

	public static function getRestEntityName(): string
	{
		return 'messageSync';
	}

	/**
	 * ALG-02: a message present in both lists travels as added, not as updated: an added message
	 * carries the full set of fields, while the client applies updates only to messages it knows.
	 * The order of subtractions is normative: completely deleted messages stay subtracted from the
	 * updated ones, otherwise a deleted message would arrive as updated.
	 */
	public function toRestFormat(array $option = []): ?array
	{
		return [
			'addedMessages' => $this->addedMessageIds,
			'updatedMessages' => array_diff(
				$this->updatedMessageIds,
				$this->addedMessageIds,
				$this->completeDeletedMessageIds,
			),
			'completeDeletedMessages' => $this->completeDeletedMessageIds,
		];
	}
}
