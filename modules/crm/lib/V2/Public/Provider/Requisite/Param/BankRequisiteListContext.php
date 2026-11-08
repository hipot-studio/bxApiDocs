<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Provider\Requisite\Param;

use Bitrix\Crm\V2\Public\EntityType;

final class BankRequisiteListContext
{
	private function __construct(
		private readonly EntityType $itemType,
		private readonly int $targetId,
		private readonly bool $forItem,
	)
	{
	}

	public static function createForRequisite(EntityType $itemType, int $requisiteId): self
	{
		return new self($itemType, $requisiteId, false);
	}

	public static function createForItem(EntityType $itemType, int $itemId): self
	{
		return new self($itemType, $itemId, true);
	}

	public function getItemType(): EntityType
	{
		return $this->itemType;
	}

	public function getTargetId(): int
	{
		return $this->targetId;
	}

	public function isForItem(): bool
	{
		return $this->forItem;
	}
}
