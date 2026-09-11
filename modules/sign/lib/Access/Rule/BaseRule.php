<?php

namespace Bitrix\Sign\Access\Rule;

use Bitrix\Crm;
use Bitrix\Main\Access\AccessibleItem;
use Bitrix\Main\Access\Rule\AbstractRule;
use Bitrix\Main\Loader;
use Bitrix\Sign\Access\ActionDictionary;
use Bitrix\Sign\Access\Model\UserModel;
use Bitrix\Sign\Access\Permission\PermissionDictionary;
use Bitrix\Sign\Access\Permission\SignPermissionDictionary;
use Bitrix\Sign\Contract;
use Bitrix\Sign\Item\Access\Document;
use Bitrix\Sign\Service\Container;

class BaseRule extends AbstractRule
{
	private ?SafeFolderRule $safeFolderRule = null;

	/**
	 * check access permission
	 *
	 * @param AccessibleItem|null $item
	 *
	 * @return bool
	 */
	public function execute(?AccessibleItem $item = null, $params = null): bool
	{
		if($this->user->isAdmin())
		{
			return true;
		}
		if (!is_array($params) || !array_key_exists('action', $params) || !is_string($params['action']))
		{
			return false;
		}
		$action = $params['action'];

		$rawPermissionId = ActionDictionary::getPermissionIdByAction($action);
		if ($rawPermissionId === null)
		{
			return false;
		}
		$permissionId = (string)$rawPermissionId;

		if ($this->isCrmEntityTypeMismatch($permissionId, $item))
		{
			return false;
		}

		$user = $this->user;
		if (!$user instanceof UserModel)
		{
			return false;
		}

		if ($item instanceof Document)
		{
			if ($item->isTemplated())
			{
				return $this->checkDocumentTemplateAccess($action, $item, $user);
			}

			// Template-action on a non-templated document — mirror of checkDocumentTemplateAccess.
			if ($this->isTemplatePermission($rawPermissionId))
			{
				return false;
			}
		}

		// Company safe folder actions are gated by SafeFolderRule (NORMATIVE ALG-01): the safe
		// access toggle is a hard prerequisite, then the folder permission owner-scope applies.
		if (SafeFolderRule::isSafeFolderAction($action))
		{
			return $this->getSafeFolderRule()->canAccessSafeFolderByOwner(
				$user,
				$item instanceof Contract\Access\AccessibleItemWithOwner ? $item->getOwnerId() : null,
				$action,
			);
		}

		if ($this->checkBinarySignPermission($permissionId))
		{
			return true;
		}
		if ($this->checkExtendedSignPermission($action, $item))
		{
			return true;
		}

		if (
			$item instanceof Contract\Access\AccessibleItemWithOwner
			&& $this->checkAccessibleItemWithOwner($action, $item)
		)
		{
			return true;
		}

		if (!Loader::includeModule('crm'))
		{
			return false;
		}

		return $this->checkCrmEntityPermission($permissionId, $item);
	}

	private function checkAccessibleItemWithOwner(string $action, Contract\Access\AccessibleItemWithOwner $item): bool
	{
		$user = $this->user;
		if (!$user instanceof UserModel)
		{
			return false;
		}
		if ($user->isAdmin())
		{
			return true;
		}

		if (!Loader::includeModule('crm'))
		{
			return false;
		}

		$itemOwnerId = $item->getOwnerId();

		return $this->checkSignPermission(ActionDictionary::getPermissionIdByAction($action), $user, $itemOwnerId);
	}

	private function checkDocumentTemplateAccess(string $action, Document $item, UserModel $user): bool
	{
		$permissionId = ActionDictionary::getPermissionIdByAction($action);
		$templatePermissionId = PermissionDictionary::getB2eDocumentToTemplatePermissionMap()[$permissionId] ?? null;

		if ($templatePermissionId === null)
		{
			if (!$this->isTemplatePermission($permissionId))
			{
				return false;
			}
			$templatePermissionId = $permissionId;
		}

		$ownerId = $item->getOwnerId();

		return $this->checkSignPermission($templatePermissionId, $user, $ownerId);
	}

	/**
	 * Whether the given permission id is a B2E template permission.
	 */
	private function isTemplatePermission(string|int|null $permissionId): bool
	{
		return in_array($permissionId, SignPermissionDictionary::getB2eTemplatePermissionIds(), true);
	}

	private function checkSignPermission(string|int $permissionId, UserModel $user, ?int $itemOwnerId = null): bool
	{
		$permissionValue = Container::instance()->getRolePermissionService()->getValueForPermission(
			$user->getRoles(),
			$permissionId,
		);
		if ($permissionValue === null || $permissionValue === \Bitrix\Crm\Service\UserPermissions::PERMISSION_NONE)
		{
			return false;
		}
		if ($itemOwnerId === null)
		{
			return true;
		}

		$userId = $user->getUserId();

		if ($permissionValue === \Bitrix\Crm\Service\UserPermissions::PERMISSION_ALL)
		{
			return true;
		}
		if ($permissionValue === \Bitrix\Crm\Service\UserPermissions::PERMISSION_SELF)
		{
			return $itemOwnerId === $userId;
		}
		if ($permissionValue === \Bitrix\Crm\Service\UserPermissions::PERMISSION_SUBDEPARTMENT)
		{
			return in_array($itemOwnerId, $user->getUserDepartmentMembers(true), true);
		}
		if ($permissionValue === \Bitrix\Crm\Service\UserPermissions::PERMISSION_DEPARTMENT)
		{
			return in_array($itemOwnerId, $user->getUserDepartmentMembers(), true);
		}

		return false;
	}

	private function isCrmEntityTypeMismatch(string $permissionId, ?AccessibleItem $item): bool
	{
		if (!$item instanceof Contract\Item\ItemWithCrmEntity)
		{
			return false;
		}

		$crmPermissionMap = PermissionDictionary::getCrmPermissionMap();
		$isCrmPermission = array_key_exists($permissionId, $crmPermissionMap);

		$itemEntityTypeId = $item->getCrmEntityTypeId();
		if ($itemEntityTypeId === null)
		{
			return $isCrmPermission;
		}

		if (!$isCrmPermission)
		{
			return false;
		}

		[, $expectedEntityTypeId] = $crmPermissionMap[$permissionId];

		return $itemEntityTypeId !== $expectedEntityTypeId;
	}

	private function checkCrmEntityPermission(string $permissionId, ?AccessibleItem $item): bool
	{
		$crmPermissionMap = PermissionDictionary::getCrmPermissionMap();
		if (!array_key_exists($permissionId, $crmPermissionMap))
		{
			return false;
		}

		$container = Crm\Service\Container::getInstance();
		[$permission, $entity] = $crmPermissionMap[$permissionId];
		$userPermissions = $container->getUserPermissions($this->user->getUserId());
		if (!method_exists($userPermissions, $permission))
		{
			return false;
		}

		$categoryId = $container->getFactory($entity)
			?->getDefaultCategory()
			?->getId()
		;
		if ($permission === 'checkAddPermissions')
		{
			return is_null($categoryId)
				? $userPermissions->entityType()->canAddItems($entity)
				: $userPermissions->entityType()->canAddItemsInCategory($entity, $categoryId)
			;
		}

		$id = $item instanceof Contract\Item\ItemWithCrmEntity ? $item->getCrmId() : 0;

		if ($id > 0)
		{
			return match ($permission)
			{
				'checkReadPermissions' => $userPermissions->item()->canRead($entity, $id),
				'checkUpdatePermissions' => $userPermissions->item()->canUpdate($entity, $id),
				'checkDeletePermissions' => $userPermissions->item()->canDelete($entity, $id),
				default => false,
			};
		}
		elseif ($categoryId > 0)
		{
			return match ($permission)
			{
				'checkReadPermissions' => $userPermissions->entityType()->canReadItemsInCategory($entity, $categoryId),
				'checkUpdatePermissions' => $userPermissions->entityType()->canUpdateItemsInCategory($entity, $categoryId),
				'checkDeletePermissions' => $userPermissions->entityType()->canDeleteItemsInCategory($entity, $categoryId),
				default => false,
			};
		}
		else
		{
			return match ($permission)
			{
				'checkReadPermissions' => $userPermissions->entityType()->canReadItems($entity),
				'checkUpdatePermissions' => $userPermissions->entityType()->canUpdateItems($entity),
				'checkDeletePermissions' => $userPermissions->entityType()->canDeleteItems($entity),
				default => false,
			};
		}
	}

	private function checkExtendedSignPermission(string $action, ?AccessibleItem $item): bool
	{
		$user = $this->user;
		if (!$user instanceof UserModel)
		{
			return false;
		}

		return $this->checkSignPermission(
			ActionDictionary::getPermissionIdByAction($action),
			$user,
			$item?->getOwnerId(),
		);
	}

	private function checkBinarySignPermission(string $permissionId): ?int
	{
		return $this->user->getPermission($permissionId);
	}

	private function getSafeFolderRule(): SafeFolderRule
	{
		return $this->safeFolderRule ??= new SafeFolderRule();
	}
}
