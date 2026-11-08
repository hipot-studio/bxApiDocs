<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Entity\Address;

use Bitrix\Crm\V2\Public\Entity\Address\AddressIdentifier;
use Bitrix\Crm\V2\Public\Entity\Address\AddressOwner;
use CCrmOwnerType;

/**
 * Where one address lives: its type, the CRM owner it belongs to and the record the row is stored on.
 *
 * Two owners live in one row of the address storage and confusing them is the largest source of mistakes
 * around addresses. `ENTITY_TYPE_ID + ENTITY_ID` name the record the row belongs to, `ANCHOR_TYPE_ID +
 * ANCHOR_ID` name the CRM owner. A contact and a company never keep an address on themselves - the row
 * names one of their requisites - while a lead, a requisite and every other owner keep the row on
 * themselves.
 *
 * The two ways are carried here as a value instead of being spelled out again by every caller, and
 * {@see self::isKeptOnARequisite()} is the single place the owner types of the first way are named. The
 * write path, the geodata and the default flag all branch on that one question.
 *
 * @internal
 */
final readonly class AddressPlacement
{
	private const OWNER_TYPES_KEPT_ON_A_REQUISITE = [CCrmOwnerType::Contact, CCrmOwnerType::Company];

	private function __construct(
		public int $typeId,
		public int $ownerTypeId,
		public int $ownerId,
		public int $recordTypeId,
		public int $recordId,
	)
	{
	}

	/**
	 * The address of a contact or a company: the row is stored on one of the requisites of the owner.
	 */
	public static function onRequisiteOfOwner(
		int $typeId,
		int $ownerTypeId,
		int $ownerId,
		int $requisiteId,
	): self
	{
		return new self($typeId, $ownerTypeId, $ownerId, CCrmOwnerType::Requisite, $requisiteId);
	}

	/**
	 * The address of an owner that keeps its addresses on itself - a lead, a requisite, anything else.
	 */
	public static function onOwnerItself(int $typeId, int $ownerTypeId, int $ownerId): self
	{
		return new self($typeId, $ownerTypeId, $ownerId, $ownerTypeId, $ownerId);
	}

	/**
	 * Whether an owner of this type keeps its addresses on one of its requisites instead of on itself.
	 *
	 * This is the only place the two ways are told apart. A caller that needs to know where a row lives,
	 * where its anchor comes from or how its default flag is decided asks here and nowhere else.
	 */
	public static function isKeptOnARequisite(int $ownerTypeId): bool
	{
		return in_array($ownerTypeId, self::OWNER_TYPES_KEPT_ON_A_REQUISITE, true);
	}

	public function getIdentifier(): AddressIdentifier
	{
		return new AddressIdentifier($this->recordTypeId, $this->recordId, $this->typeId);
	}

	public function getOwner(): AddressOwner
	{
		return new AddressOwner($this->ownerTypeId, $this->ownerId);
	}
}
