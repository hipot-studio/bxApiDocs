<?php

namespace Bitrix\Crm\Activity\Provider\ToDo;

use Bitrix\Crm\ItemIdentifier;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;

interface OptionallyConfigurable
{
	public function getId(): ?int;
	public function getProviderId(): string;
	public function getDescription(): string;
	public function getOwner(): ItemIdentifier;
	public function getCalendarEventId(): ?int;
	public function setCalendarEventId(int $id): self;
	public function setStorageElementIds($storageElementIds): self;
	public function setAdditionalFields(array $fields): self;
	public function getAdditionalFields(): array;
	public function getDeadline(): ?DateTime;
	public function getStorageElementIds(): ?array;
	public function save(array $options = []): Result;
}
