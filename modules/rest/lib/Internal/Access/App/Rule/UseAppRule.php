<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Access\App\Rule;

use Bitrix\Main\Access\AccessibleItem;
use Bitrix\Main\Access\Rule\AbstractRule;
use Bitrix\Rest\Internal\Access\App\Model\AppModel;
use Bitrix\Rest\Internal\Access\User\Model\RestUserModel;

class UseAppRule extends AbstractRule
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

		if ($item->isPersonal() && $item->getOwnerUserId() === $this->user->getUserId())
		{
			return true;
		}

		$accessCodes = $item->getAccessCodes();
		if (empty($accessCodes))
		{
			return !$item->isPersonal();
		}

		/** @var RestUserModel $user */
		$user = $this->user;

		return $user->canAccess($accessCodes);
	}
}
