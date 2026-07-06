<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Access\User\Rule;

use Bitrix\Main\Access\AccessibleItem;
use Bitrix\Main\Access\Rule\AbstractRule;
use Bitrix\Main\UserTable;
use Bitrix\Rest\Internal\Access\User\Model\RestUserModel;

class AuthorizeRule extends AbstractRule
{
	public function execute(?AccessibleItem $item = null, $params = null): bool
	{
		/** @var RestUserModel $user */
		$user = $this->user;

		$userData = $user->getData();

		if (!$userData)
		{
			return false;
		}

		if (!empty($userData['CONFIRM_CODE']))
		{
			return false;
		}

		if (
			!in_array($userData['EXTERNAL_AUTH_ID'], UserTable::getExternalUserTypes(), true)
			&& (empty($userData['LAST_LOGIN']) || empty($userData['LAST_ACTIVITY_DATE']))
			&& $userData['EXTERNAL_AUTH_ID'] !== 'rest_system'
		)
		{
			return false;
		}

		return true;
	}
}
