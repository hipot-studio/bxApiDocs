<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Access\Requisite;

use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Crm\V2\Internal\Repository\Requisite\RequisiteRepository;
use Bitrix\Crm\V2\Public\OwnerType;

/**
 * The permission model of a requisite **record**, whole and in one place.
 *
 * **One decision of the domain is not here**, and it is named so the sentence above can be read exactly.
 * Whether a user may read the presets a portal offers is decided in
 * {@see \Bitrix\Crm\V2\Public\Provider\Requisite\PresetProvider}, by a question about the type of owner
 * asked there. The four decisions below are each about a particular owner and take its identifier; that
 * one names no record at all, and it was left with its only caller rather than moved here. So the model of
 * the group does live in two places, and whether the fifth decision belongs among the four is an open
 * question of the domain - moving it changes no behaviour and is worth doing when it gets a second caller.
 *
 * A requisite carries no access control of its own - there is no permission entity for it and no role setting
 * that mentions it. Its four decisions are the four decisions about its **owner**: reading a requisite follows
 * the right to read the contact or company it belongs to, changing and erasing it follow the rights to change
 * and erase that owner, and creating one follows the right to create entities of that type together with the
 * availability of that particular owner. The last of these is wider than what legacy asks
 * (`checkCreatePermissionOwnerEntity` looks at the type alone) and is a deliberate difference: without it, the
 * right to create contacts would be enough to write into the legal details of a contact the user cannot even
 * open.
 *
 * "Add a requisite" is expressed through the right to change the owner because that is what legacy does
 * and what the interface shows: a requisite is edited from the card of its owner, and no separate right
 * ever existed to split off.
 *
 * **The my-company branch.** A company marked as a company of the portal answers by a different rule in
 * all four decisions, and the rule is kept exactly as it works today rather than as it reads: on change
 * and on deletion it comes down to CRM administrator ({@see \Bitrix\Crm\Security\EntityPermission\MyCompany}),
 * and on reading it does not look at the company at all - it answers whether the user may read companies,
 * invoices or documents of any kind. That the two disagree is a conversation with the owner of the module
 * and not something to fix here: "improving" it would quietly close or open access for every portal that
 * keeps its own company in the CRM.
 *
 * The user is an argument. Nothing here reads the current user out of the global context, so one request
 * can answer for another user - which is what the write of a requisite on behalf of a portal
 * administrator needs, and what a check of the legacy helpers could never do.
 *
 * @internal
 */
final class RequisitePermissions
{
	/**
	 * Contacts and companies have no categories of their own, so the category of the create right is
	 * always the default one - the same zero the legacy helper passes.
	 */
	private const OWNER_CATEGORY_ID = 0;

	/** Whether $userId may read the requisites of the owner. */
	public function canRead(int $ownerTypeId, int $ownerId, int $userId): bool
	{
		$permissions = $this->permissionsFor($ownerTypeId, $ownerId, $userId);
		if ($permissions === null)
		{
			return false;
		}

		if ($this->isMyCompany($ownerTypeId, $ownerId))
		{
			return $permissions->myCompany()->canReadBaseFields($ownerId);
		}

		return $permissions->item()->canRead($ownerTypeId, $ownerId);
	}

	/**
	 * Whether $userId may add a requisite to the owner: the right to create entities of that type, plus
	 * the owner itself being available to that user.
	 */
	public function canAdd(int $ownerTypeId, int $ownerId, int $userId): bool
	{
		$permissions = $this->permissionsFor($ownerTypeId, $ownerId, $userId);
		if ($permissions === null)
		{
			return false;
		}

		return
			$permissions->entityType()->canAddItemsInCategory($ownerTypeId, self::OWNER_CATEGORY_ID)
			&& $this->canUpdate($ownerTypeId, $ownerId, $userId)
		;
	}

	/** Whether $userId may change a requisite of the owner. */
	public function canUpdate(int $ownerTypeId, int $ownerId, int $userId): bool
	{
		$permissions = $this->permissionsFor($ownerTypeId, $ownerId, $userId);
		if ($permissions === null)
		{
			return false;
		}

		if ($this->isMyCompany($ownerTypeId, $ownerId))
		{
			return $permissions->myCompany()->canUpdate();
		}

		return $permissions->item()->canUpdate($ownerTypeId, $ownerId);
	}

	/** Whether $userId may erase a requisite of the owner. */
	public function canDelete(int $ownerTypeId, int $ownerId, int $userId): bool
	{
		$permissions = $this->permissionsFor($ownerTypeId, $ownerId, $userId);
		if ($permissions === null)
		{
			return false;
		}

		if ($this->isMyCompany($ownerTypeId, $ownerId))
		{
			return $permissions->myCompany()->canDelete();
		}

		return $permissions->item()->canDelete($ownerTypeId, $ownerId);
	}

	/**
	 * The permissions of $userId, or `null` when the question cannot be asked at all: an anonymous user,
	 * an owner type requisites do not belong to, or no owner named.
	 *
	 * A missing owner is refused rather than answered by the right over the type as a whole. Legacy
	 * falls back to the type when it is handed a zero, and that fallback exists for the requisite
	 * without an owner - a record the group refuses to create in the first place. Refusing keeps the one
	 * invariant of the group intact: absence, denial and an owner that is not the one of the route are
	 * indistinguishable from the outside.
	 */
	private function permissionsFor(int $ownerTypeId, int $ownerId, int $userId): ?UserPermissions
	{
		if ($userId <= 0 || $ownerId <= 0 || !in_array($ownerTypeId, RequisiteRepository::OWNER_TYPE_IDS, true))
		{
			return null;
		}

		return Container::getInstance()->getUserPermissions($userId);
	}

	/**
	 * Whether the owner is a company the portal keeps as its own.
	 *
	 * Answered by {@see \CCrmCompany::isMyCompany()} - the same source the legacy permission helpers
	 * read, so the branch is chosen on exactly the same records as today, and the one of the three
	 * sources of this flag that remembers its answer for the rest of the request.
	 */
	private function isMyCompany(int $ownerTypeId, int $ownerId): bool
	{
		return $ownerTypeId === OwnerType::COMPANY && \CCrmCompany::isMyCompany($ownerId);
	}
}
