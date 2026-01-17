<?php

namespace Bitrix\Crm\MessageSender\UI\Editor;

use Bitrix\Crm\ItemIdentifier;

final class Context implements \JsonSerializable
{
	public function __construct(
		private readonly ?int $entityTypeId = null,
		private readonly ?int $entityId = null,
		private readonly ?int $categoryId = null,
		private ?int $userId = null,
	)
	{
		$this->userId ??= \Bitrix\Crm\Service\Container::getInstance()->getContext()->getUserId();
	}

	public function getEntityTypeId(): ?int
	{
		return $this->entityTypeId;
	}

	public function getEntityId(): ?int
	{
		return $this->entityId;
	}

	public function getCategoryId(): ?int
	{
		return $this->categoryId;
	}

	public function getItemIdentifier(): ?ItemIdentifier
	{
		return ItemIdentifier::createByParams((int)$this->entityTypeId, (int)$this->entityId, $this->categoryId);
	}

	public function getUserId(): int
	{
		return $this->userId;
	}

	public function jsonSerialize(): array
	{
		return [
			'entityTypeId' => $this->entityTypeId,
			'entityId' => $this->entityId,
			'categoryId' => $this->categoryId,
			'userId' => $this->userId,
		];
	}
}
