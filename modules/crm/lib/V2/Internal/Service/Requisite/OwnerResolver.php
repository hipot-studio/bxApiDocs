<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Requisite;

use Bitrix\Crm\V2\Internal\Repository\Requisite\RequisiteRepository;
use Bitrix\Crm\V2\Public\OwnerType;

/**
 * Who a requisite may belong to, and whether the owner a call names is there.
 *
 * Two questions, and they are not the same one. A type outside the two the group answers for is a call
 * that cannot be right whoever makes it; an owner of the right type that does not exist is a call that
 * was right yesterday. The operations of the domain ask them in that order and turn both into the same
 * outward answer, absence - the invariant that keeps a client from telling a record it may not see from
 * a record that is not there.
 *
 * Neither question involves permissions: what a user may do with an owner is
 * {@see \Bitrix\Crm\V2\Internal\Access\Requisite\RequisitePermissions}, and the two are asked
 * separately because a check of existence that answered `false` for a hidden owner would tell the caller
 * which of the two happened.
 *
 * @internal
 */
final class OwnerResolver
{
	/**
	 * Whether a requisite may belong to an owner of this type at all - a contact or a company, and
	 * nothing else.
	 *
	 * Legacy is wider in practice than it reads: the type is checked when a requisite is erased by owner
	 * and never when one is written, so a requisite of a lead or of a deal is a state the storage holds
	 * today. The group does not create such records and does not answer for them.
	 */
	public function isSupportedType(int $ownerTypeId): bool
	{
		return in_array($ownerTypeId, RequisiteRepository::OWNER_TYPE_IDS, true);
	}

	/**
	 * Whether the owner exists. `false` for a type no requisite may belong to and for an identifier that
	 * is not one, so a caller never has to ask the two questions in a particular order to stay correct.
	 *
	 * Existence is read the way legacy reads it ({@see \Bitrix\Crm\EntityRequisite::validateEntityExists()}):
	 * one row of the entity of the owner, permissions deliberately not applied - whether the user may see
	 * the owner is a different question, asked by a different class.
	 */
	public function exists(int $ownerTypeId, int $ownerId): bool
	{
		if ($ownerId <= 0 || !$this->isSupportedType($ownerTypeId))
		{
			return false;
		}

		return $ownerTypeId === OwnerType::COMPANY
			? \CCrmCompany::Exists($ownerId)
			: \CCrmContact::Exists($ownerId)
		;
	}
}
