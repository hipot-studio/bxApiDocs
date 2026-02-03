<?php

namespace Bitrix\Mail\Access\Rule;

use Bitrix\Mail\Helper\MailboxAccess;
use Bitrix\Main\Access\AccessibleItem;
use Bitrix\Main\Access\Permission\PermissionDictionary as PermissionDictionaryAlias;
use Bitrix\Main\Access\Rule\AbstractRule;

class MailboxMassConnectEnterRule extends AbstractRule
{
	public function execute(?AccessibleItem $item = null, $params = null): bool
	{
		if (!isset($params['PERMISSION_ID']))
		{
			return false;
		}

		if ($this->user->isAdmin())
		{
			return true;
		}

		$permissionValue = (int)MailboxAccess::getPermissionValue(
			(string)$params['PERMISSION_ID'],
			$this->user->getUserId(),
		);

		return $permissionValue > PermissionDictionaryAlias::VALUE_NO;
	}
}
