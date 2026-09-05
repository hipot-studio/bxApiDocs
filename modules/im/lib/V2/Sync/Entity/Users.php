<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Sync\Entity;

use Bitrix\Im\V2\Sync\Entity;
use Bitrix\Im\V2\Sync\Event;

class Users implements Entity
{
	private array $updatedUserIds = [];
	private array $deletedUserIds = [];

	public function add(Event $event): void
	{
		$entityId = $event->entityId;

		switch ($event->eventName)
		{
			case Event::USER_FIRED_EVENT:
			case Event::USER_RESTORED_EVENT:
				$this->updatedUserIds[$entityId] = $entityId;
				break;
			case Event::COMPLETE_DELETE_EVENT:
				$this->deletedUserIds[$entityId] = $entityId;
				break;
		}
	}

	public function getUpdatedUserIds(): array
	{
		return $this->updatedUserIds;
	}

	public function getDeletedUserIds(): array
	{
		return $this->deletedUserIds;
	}

	/**
	 * Replaces updated-user ids with the caller-filtered visible set, so
	 * toRestFormat() emits exactly the gated set (policy lives in Entities).
	 *
	 * @param int[] $userIds
	 */
	public function setUpdatedUserIds(array $userIds): void
	{
		$this->updatedUserIds = [];
		foreach ($userIds as $userId)
		{
			$this->updatedUserIds[$userId] = $userId;
		}
	}

	/**
	 * Restricts the deleted-user ids to a caller-computed visible set.
	 *
	 * @param int[] $userIds
	 */
	public function setDeletedUserIds(array $userIds): void
	{
		$this->deletedUserIds = [];
		foreach ($userIds as $userId)
		{
			$this->deletedUserIds[$userId] = $userId;
		}
	}

	public static function getRestEntityName(): string
	{
		return 'userSync';
	}

	public function toRestFormat(array $option = []): ?array
	{
		return [
			'updatedUsers' => array_values($this->updatedUserIds),
			'deletedUsers' => array_values($this->deletedUserIds),
		];
	}
}
