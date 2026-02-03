<?php

namespace Bitrix\Mail\Access\Rule;

use Bitrix\Mail\Access\Model\MailboxModel;
use Bitrix\HumanResources\Service\Container;
use Bitrix\Mail\Access\Permission\PermissionVariablesDictionary;
use Bitrix\Mail\Helper\MailboxAccess;
use Bitrix\Mail\Integration\HumanResources\NodeMemberService;
use Bitrix\Main\Access\AccessibleItem;
use Bitrix\Main\Access\Rule\AbstractRule;

class MailboxBaseRule extends AbstractRule
{
	public const PERMISSION_ID_KEY = 'PERMISSION_ID';

	public function execute(?AccessibleItem $item = null, $params = null): bool
	{
		if (
			!($item instanceof MailboxModel)
			|| !$item->getId()
			|| !$item->getOwnerId()
			|| !is_array($params)
			|| !isset($params[self::PERMISSION_ID_KEY])
		)
		{
			return false;
		}

		$accessibleUser = $this->user;
		if ($accessibleUser->getUserId() === $item->getOwnerId() || $accessibleUser->isAdmin())
		{
			return true;
		}

		$permissionId = (string)$params[self::PERMISSION_ID_KEY];
		$permissionValue = (int)MailboxAccess::getPermissionValue($permissionId, $accessibleUser->getUserId());
		if ($permissionValue === PermissionVariablesDictionary::VARIABLE_NONE)
		{
			return false;
		}

		if ($permissionValue === PermissionVariablesDictionary::VARIABLE_ALL)
		{
			return true;
		}

		$nodeRepository = Container::getNodeRepository();
		$userDepartmentCollection = $nodeRepository->findAllByUserId($accessibleUser->getUserId());
		if ($userDepartmentCollection->empty())
		{
			return false;
		}

		$userDepartmentIds = $userDepartmentCollection->getIds();

		$withSubDepartments = ($permissionValue === PermissionVariablesDictionary::VARIABLE_DEPARTMENT_WITH_SUBDEPARTMENTS);

		$foundUserIds = NodeMemberService::filterUsersByDepartmentIds(
			[$item->getOwnerId()],
			$userDepartmentIds,
			$withSubDepartments,
		);

		return !empty($foundUserIds);
	}
}
