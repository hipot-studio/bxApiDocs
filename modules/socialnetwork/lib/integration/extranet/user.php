<?php

declare (strict_types=1);

namespace Bitrix\Socialnetwork\Integration\Extranet;

use Bitrix\Extranet\Enum\User\ExtranetRole;
use Bitrix\Extranet\Service\ServiceContainer;
use Bitrix\Main\Loader;

class User
{
	// null means the extranet layer gives no verdict: the module is unavailable or there is no user to ask about
	public static function isExtranet(int $userId): ?bool
	{
		if (!Loader::includeModule('extranet') || $userId <= 0)
		{
			return null;
		}

		// Extranet group membership is the legacy marker, the registry role is the modern one, and they may diverge:
		// the registry is filled only when humanresources is available, the group stays after the row is dropped
		return (new \Bitrix\Extranet\User($userId))->isExtranet() || self::hasCurrentExtranetRole($userId);
	}

	private static function hasCurrentExtranetRole(int $userId): bool
	{
		$extranetUser = ServiceContainer::getInstance()->getExtranetUserRepository()->getByUserId($userId);

		if ($extranetUser === null)
		{
			return false;
		}

		return in_array($extranetUser->getRole(), ExtranetRole::getCurrentExtranetRole(), true);
	}

	public static function isCollaber(int $userId): bool
	{
		if (!Loader::includeModule('extranet'))
		{
			return false;
		}

		return
			ServiceContainer::getInstance()
				->getCollaberService()
				->isCollaberById($userId)
		;
	}
}