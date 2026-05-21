<?php

namespace Bitrix\Crm\Service\UserPermissions;

use Bitrix\Crm\Security\EntityPermission\ApproveCustomPermsToExistRole;
use Bitrix\Crm\Security\Role\Manage\Permissions\Event\Read;
use Bitrix\Crm\Security\Role\PermissionsManager;
use Bitrix\Crm\Service\UserPermissions;

final class Event
{
	private const ENTITY_TYPES_WITH_EVENT_PERMISSION = [
		\CCrmOwnerType::Lead,
		\CCrmOwnerType::Deal,
		\CCrmOwnerType::Quote,
		\CCrmOwnerType::Order,
		\CCrmOwnerType::OrderPayment,
		\CCrmOwnerType::OrderShipment,
		\CCrmOwnerType::SmartInvoice,
	];

	public function __construct(
		private readonly PermissionsManager $permissionsManager,
		private readonly Admin $admin,
	)
	{
	}

	/**
	 * Whether the given entity type requires event permission check.
	 *
	 * For Contact/Company, the check depends on category:
	 * default category (0) = CRM entity, needs permission;
	 * non-default category (e.g. contractor) = not a CRM entity, no permission needed.
	 */
	public static function isEntityTypeWithEventPermission(int $entityTypeId, int $categoryId = 0): bool
	{
		if (in_array($entityTypeId, self::ENTITY_TYPES_WITH_EVENT_PERMISSION, true))
		{
			return true;
		}

		if (in_array($entityTypeId, [\CCrmOwnerType::Contact, \CCrmOwnerType::Company], true))
		{
			return $categoryId === 0;
		}

		return \CCrmOwnerType::isPossibleDynamicTypeId($entityTypeId);
	}

	public function canRead(): bool
	{
		if ((new ApproveCustomPermsToExistRole())->hasWaitingPermission(new Read()))
		{
			return true;
		}

		if ($this->admin->isCrmAdmin())
		{
			return true;
		}

		return $this->permissionsManager->hasPermission(
			\Bitrix\Crm\Security\Role\Manage\Entity\Event::ENTITY_CODE,
			UserPermissions::OPERATION_READ
		);
	}
}
