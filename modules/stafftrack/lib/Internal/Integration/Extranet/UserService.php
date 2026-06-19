<?php
namespace Bitrix\StaffTrack\Internal\Integration\Extranet;

use Bitrix\Extranet\Service\ServiceContainer;
use Bitrix\Main\Loader;

class UserService
{
	public static function isCollaber(int $userId): bool
	{
		if (!Loader::includeModule('extranet') || $userId <= 0)
		{
			return false;
		}

		$container = class_exists(ServiceContainer::class) ? ServiceContainer::getInstance() : null;

		return $container?->getCollaberService()?->isCollaberById($userId) ?? false;
	}

	public static function isExtranet(int $userId): bool
	{
		if (!Loader::includeModule('extranet') || $userId <= 0)
		{
			return false;
		}

		$serviceContainer = class_exists(ServiceContainer::class) ? ServiceContainer::getInstance() : null;

		return $serviceContainer?->getUserService()?->isCurrentExtranetUserById($userId) ?? false;
	}
}