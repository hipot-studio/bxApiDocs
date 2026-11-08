<?php

namespace Bitrix\Crm\Service\UserPermissions;

use Bitrix\Crm\Category\PermissionEntityTypeHelper;
use Bitrix\Crm\Security\EntityPermission\ApproveCustomPermsToExistRole;
use Bitrix\Crm\Security\Role\Manage\Permissions\MyCardView;
use Bitrix\Crm\Security\Role\PermissionsManager;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\UserPermissions;

/**
 * @internal
 * Do not use directly, only through \Bitrix\Crm\Service\Container::getInstance()->getUserPermissions()->entityEditor()
 */
final class EntityEditor
{
	public function __construct(
		private readonly int $userId,
		private readonly PermissionsManager $permissionsManager,
		private readonly Admin $admin,
		private readonly UserPermissions\EntityPermissions\Admin $entityAdmin,
		private readonly Configuration $configuration,
	)
	{
	}

	/**
	 * Can user switch entity editor to personal view
	 * @param int $entityTypeId
	 * @param int|null $categoryId
	 * @return bool
	 */
	public function canSwitchToPersonalView(int $entityTypeId, ?int $categoryId): bool
	{
		if ($this->entityAdmin->isAdminForEntity($entityTypeId, $categoryId))
		{
			return true;
		}

		$permission = new MyCardView();
		if ((new ApproveCustomPermsToExistRole())->hasWaitingPermission($permission))
		{
			return true;
		}
		$factory = Container::getInstance()->getFactory($entityTypeId);
		if ( !$factory || $factory->hasCustomPermissionsUI())
		{
			return true;
		}

		$contactCategoryId = Container::getInstance()->getFactory(\CCrmOwnerType::Contact)
			->getCategoryByCode('SMART_DOCUMENT_CONTACT')
			?->getId();

		if ($entityTypeId === \CCrmOwnerType::Contact && $contactCategoryId === $categoryId)
		{
			return true;
		}
		$entityName = (new PermissionEntityTypeHelper($entityTypeId))->getPermissionEntityTypeForCategory($categoryId);

		return $this->permissionsManager->hasPermissionLevel($entityName, $permission->code(), UserPermissions::PERMISSION_ALL);
	}

	/**
	 * Can user edit representation of entity editor in common view
	 *
	 * $entityTypeId and $categoryId name the card whose common layout is being read or written, so that
	 * the answer belongs to the contour that card is configured in ({@see ConfigurationScope}). A
	 * surface that names no card of its own passes `null` and is answered for CRM as a whole - the
	 * former check of this path was the global one, so nothing changes for it before the operations are
	 * registered.
	 *
	 * @param int|null $entityTypeId
	 * @param int|null $categoryId
	 * @return bool
	 */
	public function canEditCommonView(?int $entityTypeId, ?int $categoryId): bool
	{
		return $this->configuration->canManageCommonViews(
			ConfigurationCallSite::EntityEditorPermissions,
			$entityTypeId === null
				? ConfigurationScope::ofCrm()
				: ConfigurationScope::ofEntityType($entityTypeId, $categoryId),
		);
	}

	/**
	 * Can user edit representation of entity editor in personal view for a user
	 * @param int $userId
	 * @return bool
	 */
	public function canEditPersonalViewForUser(int $userId): bool
	{
		if ($this->admin->isCrmAdmin())
		{
			return true;
		}

		return $userId === $this->userId;
	}
}
