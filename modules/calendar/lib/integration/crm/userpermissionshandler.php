<?php

namespace Bitrix\Calendar\Integration\Crm;

use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Main\Loader;
use CCrmOwnerType;

class UserPermissionsHandler
{
	private static function isAvailable(): bool
	{
		return Loader::includeModule('crm');
	}

	public static function getUserPermissions(int $userId): ?UserPermissions
	{
		if (self::isAvailable())
		{
			return Container::getInstance()->getUserPermissions($userId);
		}

		return null;
	}

	public static function canReadDeal(int $userId, int $dealId): bool
	{
		$userPermissions = self::getUserPermissions($userId);

		return $userPermissions !== null && $userPermissions->item()->canRead(CCrmOwnerType::Deal, $dealId);
	}
}
