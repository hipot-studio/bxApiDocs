<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Access\Currency;

use Bitrix\Crm\Service\Container;

class CurrencyAdminAccess
{
	public const ERROR_ACCESS_DENIED = 'CRM_CURRENCY_ADMIN_ACCESS_DENIED';

	public function canManage(int $userId): bool
	{
		return $userId > 0
			&& Container::getInstance()->getUserPermissions($userId)->isCrmAdmin();
	}
}
