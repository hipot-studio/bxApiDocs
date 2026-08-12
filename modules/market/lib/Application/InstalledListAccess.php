<?php

declare(strict_types=1);

namespace Bitrix\Market\Application;

use Bitrix\Main\Loader;
use Bitrix\Main\SystemException;
use Bitrix\Rest\OAuthService;

final class InstalledListAccess
{
	public static function isRestAvailable(): bool
	{
		return Loader::includeModule('rest');
	}

	public static function isAdmin(): bool
	{
		return self::isRestAvailable() && \CRestUtil::isAdmin();
	}

	/**
	 * @throws SystemException
	 */
	public static function ensureOAuthServiceRegistered(): bool
	{
		if (!self::isRestAvailable())
		{
			return false;
		}

		if (OAuthService::getEngine()->isRegistered())
		{
			return true;
		}

		OAuthService::register();
		OAuthService::getEngine()->getClient()->getApplicationList();

		return OAuthService::getEngine()->isRegistered();
	}

	public static function canView(): bool
	{
		if (!self::isAdmin())
		{
			return false;
		}

		try
		{
			return self::ensureOAuthServiceRegistered();
		}
		catch (SystemException)
		{
			return false;
		}
	}
}
