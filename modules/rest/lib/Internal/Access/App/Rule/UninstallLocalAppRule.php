<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Access\App\Rule;

use Bitrix\Main\Access\AccessibleItem;
use Bitrix\Main\Access\Rule\AbstractRule;
use Bitrix\Rest\Internal\Access\App\AppAction;
use Bitrix\Rest\Internal\Access\App\Model\AppModel;
use Bitrix\Rest\Internal\Access\User\Model\RestUserModel;
use Bitrix\Rest\Internal\Entity\Access\EntityType;
use Bitrix\Rest\Internal\Service\AccessCodesService;

class UninstallLocalAppRule extends AbstractRule
{
	public function execute(?AccessibleItem $item = null, $params = null): bool
	{
		if ($this->user->isAdmin())
		{
			return true;
		}

		if (!$item instanceof AppModel)
		{
			return false;
		}

		if ($item->isPersonal())
		{
			return false;
		}

		$permissionType = AppAction::UninstallLocalApp->getPermissionType();
		if ($permissionType === null)
		{
			return false;
		}

		$service = new AccessCodesService();
		$allowedCodes = $service->getAccessCodes(EntityType::LocalApp, $permissionType);

		if (empty($allowedCodes))
		{
			return false;
		}

		/** @var RestUserModel $user */
		$user = $this->user;

		return $user->canAccess($allowedCodes);
	}
}
