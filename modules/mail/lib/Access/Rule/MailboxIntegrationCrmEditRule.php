<?php

declare(strict_types=1);

namespace Bitrix\Mail\Access\Rule;

use Bitrix\Mail\Helper\MailboxAccess;
use Bitrix\Main\Access\AccessibleItem;
use Bitrix\Main\Access\Permission\PermissionDictionary as PermissionDictionaryAlias;
use Bitrix\Main\Access\Rule\AbstractRule;

class MailboxIntegrationCrmEditRule extends AbstractRule
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

		$permissionValue = (int)MailboxAccess::getPermissionValue(
			(string)$params[self::PERMISSION_ID_KEY],
			$this->user->getUserId(),
		);

		return $permissionValue === PermissionDictionaryAlias::VALUE_YES
			&& \Bitrix\Mail\Integration\Crm\Permissions::getInstance()->hasAccessToCrm()
		;
	}
}
