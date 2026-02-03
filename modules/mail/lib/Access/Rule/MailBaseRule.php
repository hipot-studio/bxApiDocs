<?php

namespace Bitrix\Mail\Access\Rule;

use Bitrix\Mail\Helper\MailAccess;
use Bitrix\Main\Access\AccessibleItem;
use Bitrix\Main\Access\Permission\PermissionDictionary as PermissionDictionaryAlias;
use Bitrix\Main\Access\Rule\AbstractRule;

class MailBaseRule extends AbstractRule
{
	public const PERMISSION_ID_KEY = 'PERMISSION_ID';

	public function execute(?AccessibleItem $item = null, $params = null): bool
	{
		if (!isset($params[self::PERMISSION_ID_KEY]))
		{
			return false;
		}

		if ($this->user->isAdmin())
		{
			return true;
		}

		$permissionValue = (int)MailAccess::getPermissionValue(
			(string)$params[self::PERMISSION_ID_KEY],
			$this->user->getUserId(),
		);

		return $permissionValue === PermissionDictionaryAlias::VALUE_YES;
	}
}
