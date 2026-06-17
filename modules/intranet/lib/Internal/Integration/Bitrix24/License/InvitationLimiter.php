<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\Bitrix24\License;

use Bitrix\Bitrix24\License;
use Bitrix\Bitrix24\LicenseScanner\Manager;
use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\Date;

class InvitationLimiter
{
	protected bool $isModuleIncluded;

	public function __construct()
	{
		$this->isModuleIncluded = Loader::includeModule('bitrix24');
	}

	public function isExceeded(): bool
	{
		if (!$this->isModuleIncluded)
		{
			return false;
		}

		$managedCache = Application::getInstance()->getManagedCache();
		$cacheKey = self::getCacheKey();

		if ($managedCache->read(86400, $cacheKey, 'intranet_invitation_limiter'))
		{
			$isExceeded = (bool)$managedCache->get($cacheKey);
		}
		else
		{
			$isExceeded =
				Manager::getInstance()
				->getInvitationDailyLimiter()
				->isLimitReached(License::getCurrent()->getCode())
			;

			$managedCache->set($cacheKey, $isExceeded);
		}

		return $isExceeded;
	}

	public static function clearCache(): void
	{
		if (Loader::includeModule('bitrix24'))
		{
			Application::getInstance()->getManagedCache()->clean(self::getCacheKey(), 'intranet_invitation_limiter');
		}
	}

	private static function getCacheKey(): string
	{
		return 'invited_users_on_portal_' . License::getCurrent()->getCode() . '_' . (new Date())->format('Y-m-d');
	}
}
