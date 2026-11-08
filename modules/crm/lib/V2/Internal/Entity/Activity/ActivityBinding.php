<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Entity\Activity;

use Bitrix\Crm\V2\Public\ItemId;
use Bitrix\Crm\V2\Public\ItemIdFactory;
use Bitrix\Crm\V2\Public\OwnerType;
use Bitrix\Main\ArgumentException;

/**
 * One binding of an activity to the CRM object it belongs to (a row of `b_crm_act_bind`).
 *
 * The owner is addressed by {@see OwnerType} rather than by {@see \Bitrix\Crm\V2\Public\EntityType}
 * because a binding is not restricted to item types: the legacy write path accepts any defined
 * owner type, so orders and invoices are bound too. {@see self::getItemId()} narrows the owner to
 * an {@see ItemId} where the owner is an item.
 */
final class ActivityBinding
{
	private readonly OwnerType $ownerType;

	private readonly int $ownerId;

	public function __construct(OwnerType $ownerType, int $ownerId)
	{
		if ($ownerId <= 0)
		{
			throw new ArgumentException("Binding owner ID must be positive, got: {$ownerId}");
		}

		$this->ownerType = $ownerType;
		$this->ownerId = $ownerId;
	}

	public static function ofItem(ItemId $itemId): self
	{
		return new self($itemId->getEntityType(), $itemId->getId());
	}

	public function getOwnerType(): OwnerType
	{
		return $this->ownerType;
	}

	public function getOwnerId(): int
	{
		return $this->ownerId;
	}

	/**
	 * The owner as an item identifier, or null when the owner is not an item entity
	 * (an order, an invoice, a call list).
	 */
	public function getItemId(): ?ItemId
	{
		return ItemIdFactory::fromParams($this->ownerType->getId(), $this->ownerId);
	}

	/**
	 * Identity of the binding inside one activity: two bindings with the same key are the same
	 * binding. The write path deduplicates by it and the change set compares by it.
	 */
	public function getKey(): string
	{
		return $this->ownerType->getId() . '_' . $this->ownerId;
	}

	public function equals(self $other): bool
	{
		return $this->getKey() === $other->getKey();
	}
}
