<?php

namespace Bitrix\Im\V2\Sync;

use Bitrix\Main\Type\DateTime;

class Event
{
	public const DELETE_EVENT = 'delete';
	public const COMPLETE_DELETE_EVENT = 'completeDelete';
	public const ADD_EVENT = 'add';
	public const READ_ALL_EVENT = 'readAll';
	public const READ_ALL_BY_TYPE_EVENT = 'readAllByType';
	public const USER_FIRED_EVENT = 'userFired';
	public const USER_RESTORED_EVENT = 'userRestored';
	public const CHAT_ENTITY = 'chat';
	public const CHAT_CLEANUP_ENTITY = 'chatCleanup';
	public const PIN_MESSAGE_ENTITY = 'pin';
	public const PERSONAL_PIN_MESSAGE_ENTITY = 'personalPin';
	public const MESSAGE_ENTITY = 'message';
	public const UPDATED_MESSAGE_ENTITY = 'updatedMessage';
	public const USER_ENTITY = 'user';
	public const DELETE_HISTORY_FOR_SELF_EVENT = 'deleteHistoryForSelf';
	public const DELETE_HISTORY_FOR_ALL_EVENT = 'deleteHistoryForAll';

	private ?int $id;
	private DateTime $dateCreate;
	public string $entityType;
	public int $entityId;
	public string $eventName;

	public function __construct(
		string $eventName,
		string $entityType,
		int $entityId,
		?DateTime $dateCreate = null,
		?int $id = null
	)
	{
		$dateCreate ??= new DateTime();
		$this->eventName = $eventName;
		$this->entityType = $entityType;
		$this->entityId = $entityId;
		$this->dateCreate = $dateCreate;
		$this->id = $id;
	}

	public function getId(): ?int
	{
		return $this->id;
	}

	public function getDateCreate(): DateTime
	{
		return $this->dateCreate;
	}

	public static function initByEntity(array $entity): self
	{
		return new self(
			$entity['EVENT'] ?? '',
			$entity['ENTITY_TYPE'] ?? '',
			$entity['ENTITY_ID'] ?? 0,
			$entity['DATE_CREATE'] ?? null,
			$entity['ID'] ?? null
		);
	}
}
