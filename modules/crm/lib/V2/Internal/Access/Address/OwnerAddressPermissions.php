<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Access\Address;

use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Crm\Security\OwnerEntityListRestriction;
use Bitrix\Crm\V2\Internal\Repository\Item\CompanyRepository;
use Bitrix\Crm\V2\Internal\Repository\Item\ContactRepository;
use Bitrix\Crm\V2\Internal\Repository\Item\ItemRepositoryInterface;
use CCrmCompany;
use CCrmOwnerType;

/**
 * Access rules of an address of a contact or a company.
 *
 * An address has no rights of its own: it follows the CRM owner kept in the anchor columns of the row.
 * Reading follows the read right of that owner, and changing it - adding included - follows the update
 * right: adding an address may create a requisite of the owner and move its default one, which the
 * interface allows only to someone who may change the owner.
 *
 * A company marked as "my company" is the exception. Its requisites belong to the requisite domain, where
 * changing anything requires a CRM administrator and reading follows the base fields rule of my company.
 * The address group keeps parity with that domain rather than with the old REST of addresses, which does
 * not know the case at all.
 *
 * A question about a stored row names the type of the owner and may name its identifier as well; that
 * identifier is a refinement and not a second key, {@see self::resolveOwnerIdOfAddress()}. Questions about
 * an owner alone - reading its list, adding an address to it - always name a whole owner: there is no row
 * to take one from.
 *
 * The class only answers questions. Turning a denial into an answer for the client is the job of the
 * caller, and there is one answer for all of them: a missing row, a row anchored to another owner and a
 * row of an owner the user may not see have to be indistinguishable from the outside.
 *
 * @internal
 */
final class OwnerAddressPermissions
{
	private const ADDRESS_OWNER_TYPES = [CCrmOwnerType::Lead, CCrmOwnerType::Contact, CCrmOwnerType::Company];

	/**
	 * The owners the group serves, each with the storage the existence of one is read from,
	 * {@see self::isSupportedOwner()}.
	 *
	 * @var array<int, class-string<ItemRepositoryInterface>>
	 */
	private const OWNER_REPOSITORY_BY_TYPE = [
		CCrmOwnerType::Contact => ContactRepository::class,
		CCrmOwnerType::Company => CompanyRepository::class,
	];

	/**
	 * @param int|null $userId `null` means the current user.
	 */
	public function __construct(private readonly ?int $userId = null)
	{
	}

	/**
	 * Whether the user may see the addresses of the owner.
	 *
	 * An owner that keeps no addresses on a requisite, and an owner that does not exist, are denied: for
	 * the caller they are the same as an owner without rights.
	 */
	public function canReadOwner(int $ownerTypeId, int $ownerId): bool
	{
		if (!self::isSupportedOwner($ownerTypeId, $ownerId))
		{
			return false;
		}

		if (self::isMyCompany($ownerTypeId, $ownerId))
		{
			return $this->getUserPermissions()->myCompany()->canReadBaseFields($ownerId);
		}

		return $this->getUserPermissions()->item()->canRead($ownerTypeId, $ownerId);
	}

	/**
	 * Whether the user may add, change or delete an address of the owner. All three follow the update
	 * right of the owner: an address is a field of its owner, not an entity with a lifecycle of its own.
	 */
	public function canWriteOwner(int $ownerTypeId, int $ownerId): bool
	{
		if (!self::isSupportedOwner($ownerTypeId, $ownerId))
		{
			return false;
		}

		if (self::isMyCompany($ownerTypeId, $ownerId))
		{
			return $this->getUserPermissions()->myCompany()->canUpdate();
		}

		return $this->getUserPermissions()->item()->canUpdate($ownerTypeId, $ownerId);
	}

	public static function buildAnchorOwnerReadRestriction(?int $userId = null): ?array
	{
		return (new OwnerEntityListRestriction(Container::getInstance()->getUserPermissions($userId)))
			->buildFilter(self::ADDRESS_OWNER_TYPES, 'ANCHOR_TYPE_ID', 'ANCHOR_ID')
		;
	}

	/**
	 * Whether the user may see this very row through the route of this very owner.
	 *
	 * The row is addressed by an identifier of a table shared by leads, requisites, contacts and
	 * companies, so belonging to the owner of the route is checked here and not left to the caller.
	 *
	 * @param array<string, mixed> $addressRow Row as the address repository returns it.
	 * @param int $ownerId Identifier of the owner, `0` for any owner of this type,
	 *        {@see self::resolveOwnerIdOfAddress()}.
	 */
	public function canReadAddress(array $addressRow, int $ownerTypeId, int $ownerId): bool
	{
		$addressOwnerId = self::resolveOwnerIdOfAddress($addressRow, $ownerTypeId, $ownerId);

		return $addressOwnerId > 0 && $this->canReadOwner($ownerTypeId, $addressOwnerId);
	}

	/**
	 * Whether the user may change or delete this very row through the route of this very owner.
	 *
	 * @param array<string, mixed> $addressRow Row as the address repository returns it.
	 * @param int $ownerId Identifier of the owner, `0` for any owner of this type,
	 *        {@see self::resolveOwnerIdOfAddress()}.
	 */
	public function canWriteAddress(array $addressRow, int $ownerTypeId, int $ownerId): bool
	{
		$addressOwnerId = self::resolveOwnerIdOfAddress($addressRow, $ownerTypeId, $ownerId);

		return $addressOwnerId > 0 && $this->canWriteOwner($ownerTypeId, $addressOwnerId);
	}

	/**
	 * The owner whose rights decide this row, or zero when the row is not reachable through this route at
	 * all. The single place that answers whom an address belongs to.
	 *
	 * The type of the owner is always the type of the route: an address anchored to a company is not
	 * reachable as an address of a contact, whatever its identifier.
	 *
	 * The identifier is a refinement of the route and may be left out. A caller that names one asks for
	 * this very address of this very owner and gets nothing when the row is anchored elsewhere. A caller
	 * that passes zero names no owner in particular - it holds an identifier of an address and the type of
	 * the entity it came for, which is the whole question a per-address route can ask - and then the owner
	 * of the row itself is the one the rights are asked about. Zero therefore widens which rows may be
	 * named, never whose rights are checked: an address of an owner the user may not see stays unreachable
	 * either way, and a row carrying no anchor belongs to nobody and is refused.
	 *
	 * @param array<string, mixed> $addressRow
	 */
	private static function resolveOwnerIdOfAddress(array $addressRow, int $ownerTypeId, int $ownerId): int
	{
		if ((int)($addressRow['ANCHOR_TYPE_ID'] ?? 0) !== $ownerTypeId)
		{
			return 0;
		}

		$anchorId = (int)($addressRow['ANCHOR_ID'] ?? 0);

		return $ownerId === 0 || $ownerId === $anchorId ? $anchorId : 0;
	}

	/**
	 * Whether the pair names an owner this group can answer about at all: one of the two types it serves,
	 * an identifier that is one, and an entity that is really there.
	 *
	 * Existence is checked here and nowhere else. A right is answered by the attributes of a role and never
	 * looks the entity up, so without this read an owner nobody ever created would come back as readable or
	 * writable and let the caller reach the write path of an entity that is not there. The read costs one
	 * query of the identifier: nothing on the way to an address of an owner reads the owner itself, so
	 * there is no read to take the answer from.
	 *
	 * The rule belongs to this class rather than to the operations, and to this class alone: the line of the
	 * legacy facade of the old address class checks no rights, never reaches this class and keeps writing an
	 * address of an owner that does not exist exactly as it does today.
	 */
	private static function isSupportedOwner(int $ownerTypeId, int $ownerId): bool
	{
		return $ownerId > 0
			&& isset(self::OWNER_REPOSITORY_BY_TYPE[$ownerTypeId])
			&& self::exists($ownerTypeId, $ownerId);
	}

	private static function exists(int $ownerTypeId, int $ownerId): bool
	{
		$repositoryClass = self::OWNER_REPOSITORY_BY_TYPE[$ownerTypeId];

		return (new $repositoryClass())->getById($ownerId, ['ID']) !== null;
	}

	private static function isMyCompany(int $ownerTypeId, int $ownerId): bool
	{
		return $ownerTypeId === CCrmOwnerType::Company && CCrmCompany::isMyCompany($ownerId);
	}

	private function getUserPermissions(): UserPermissions
	{
		return Container::getInstance()->getUserPermissions($this->userId);
	}
}
