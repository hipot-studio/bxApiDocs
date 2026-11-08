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

		$edition = License::getCurrent()->getCode();
		if ($edition === null)
		{
			return true;
		}

		$managedCache = Application::getInstance()->getManagedCache();
		$cacheKey = self::getCacheKey($edition);

		if ($managedCache->read(86400, $cacheKey, 'intranet_invitation_limiter'))
		{
			$isExceeded = (bool)$managedCache->get($cacheKey);
		}
		else
		{
			$isExceeded =
				Manager::getInstance()
				->getInvitationDailyLimiter()
				->isLimitReached($edition)
			;

			$managedCache->set($cacheKey, $isExceeded);
		}

		return $isExceeded;
	}

	public static function clearCache(): void
	{
		if (Loader::includeModule('bitrix24'))
		{
			$edition = License::getCurrent()->getCode();
			if ($edition !== null)
			{
				Application::getInstance()->getManagedCache()->clean(
					self::getCacheKey($edition),
					'intranet_invitation_limiter',
				);
			}
		}
	}

	private static function getCacheKey(string $edition): string
	{
		return 'invited_users_on_portal_' . $edition . '_' . (new Date())->format('Y-m-d');
	}
}
