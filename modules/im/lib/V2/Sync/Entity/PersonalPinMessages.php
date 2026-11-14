<?php

namespace Bitrix\Im\V2\Sync\Entity;

use Bitrix\Im\V2\Link\PersonalPin\PersonalPinCollection;
use Bitrix\Im\V2\Sync\Entity;
use Bitrix\Im\V2\Sync\Event;

class PersonalPinMessages implements Entity
{
	private array $pinIds = [];
	private array $deletedPinIds = [];

	public function add(Event $event): void
	{
		$entityId = $event->entityId;

		switch ($event->eventName)
		{
			case Event::DELETE_EVENT:
				$this->deletedPinIds[$entityId] = $entityId;
				break;
			case Event::ADD_EVENT:
				$this->pinIds[$entityId] = $entityId;
				break;
		}
	}

	/**
	 * The journal gives ids, not the right to read them: the records are taken under the user of the
	 * request, so an id of someone else's mark turns into nothing.
	 */
	public function getPinCollection(int $requesterId): PersonalPinCollection
	{
		return PersonalPinCollection::findByIdsForOwner($this->pinIds, $requesterId);
	}

	public static function getRestEntityName(): string
	{
		return 'personalPinSync';
	}

	public function toRestFormat(array $option = []): ?array
	{
		return [
			'addedPins' => array_values($this->pinIds),
			'deletedPins' => array_values($this->deletedPinIds),
		];
	}
}
