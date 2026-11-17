<?php

namespace Bitrix\Mail\Access\Rule;

use Bitrix\Mail\Access\Permission\PermissionDictionary;
use Bitrix\Main\Access\AccessibleItem;
use Bitrix\Main\Access\Rule\AbstractRule;

class MessageDeleteRule extends AbstractRule
{
	public function execute(?AccessibleItem $item = null, $params = null): bool
	{
		if ($this->user->getUserId() <= 0)
		{
			return false;
		}

		if ($this->user->isAdmin())
		{
			return true;
		}

		$permissionValue = $this->user->getPermission(PermissionDictionary::MAIL_MESSAGE_DELETE);

		return $permissionValue === null || $permissionValue === PermissionDictionary::VALUE_YES;
	}
}
