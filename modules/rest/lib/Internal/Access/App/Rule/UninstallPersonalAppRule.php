<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Access\App\Rule;

use Bitrix\Main\Access\AccessibleItem;
use Bitrix\Main\Access\Rule\AbstractRule;
use Bitrix\Rest\Internal\Access\App\Model\AppModel;

class UninstallPersonalAppRule extends AbstractRule
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

		return $item->isPersonal() && $item->getOwnerUserId() === $this->user->getUserId();
	}
}
