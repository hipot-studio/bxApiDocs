<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Integration\Main\EventHandlers;

use Bitrix\Main\DI\ServiceLocator;

class OnAfterUserLogoutEventHandler
{
	public static function handle(array &$arParams): void
	{
		self::clearQuickAccessUserToken();
	}

	private static function clearQuickAccessUserToken(): void
	{
		$userQuickAccessTokenManager = ServiceLocator::getInstance()->get('disk.userQuickAccessTokenManager');
		$userQuickAccessTokenManager->clearUserToken();
	}
}
