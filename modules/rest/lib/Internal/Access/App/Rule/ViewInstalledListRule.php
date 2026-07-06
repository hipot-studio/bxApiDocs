<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Access\App\Rule;

use Bitrix\Main\Access\AccessibleItem;
use Bitrix\Main\Access\Rule\AbstractRule;
use Bitrix\Rest\Internal\Access\User\Model\RestUserModel;

class ViewInstalledListRule extends AbstractRule
{
	public function execute(?AccessibleItem $item = null, $params = null): bool
	{
		if ($this->user->isAdmin())
		{
			return true;
		}

		$accessList = \CRestUtil::getInstallAccessList();

		if (empty($accessList))
		{
			return false;
		}

		/** @var RestUserModel $user */
		$user = $this->user;

		return $user->canAccess($accessList);
	}
}
