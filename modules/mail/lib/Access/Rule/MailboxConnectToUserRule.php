<?php

namespace Bitrix\Mail\Access\Rule;

use Bitrix\HumanResources\Service\Container;
use Bitrix\Mail\Access\MailAccessController;
use Bitrix\Mail\Access\MailActionDictionary;
use Bitrix\Mail\Access\Model\UserItemModel;
use Bitrix\Mail\Access\Permission\PermissionVariablesDictionary;
use Bitrix\Mail\Helper\MailboxAccess;
use Bitrix\Mail\Integration\HumanResources\NodeMemberService;
use Bitrix\Main\Access\AccessibleItem;
use Bitrix\Main\Access\Rule\AbstractRule;

class MailboxConnectToUserRule extends AbstractRule
{
	public const PERMISSION_ID_KEY = 'PERMISSION_ID';

	public function execute(?AccessibleItem $item = null, $params = null): bool
	{
		if (
			!$item instanceof UserItemModel
			|| !isset($params[self::PERMISSION_ID_KEY])
			|| $item->getId() <= 0
		)
		{
			return false;
		}

		$accessibleUser = $this->user;
		if ($accessibleUser->isAdmin())
		{
			return true;
		}

		$ownerId = $item->getId();
		if ($accessibleUser->getUserId() === $ownerId)
		{
			return MailAccessController::can($ownerId, MailActionDictionary::ACTION_MAILBOX_SELF_CONNECT);
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
			[$ownerId],
			$userDepartmentIds,
			$withSubDepartments,
		);

		return !empty($foundUserIds);
	}
}
