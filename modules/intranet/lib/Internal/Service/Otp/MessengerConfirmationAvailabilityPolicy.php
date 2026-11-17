<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Service\Otp;

use Bitrix\Intranet\Internal\Integration\Security\SecondFactorCodeProvider;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\ModuleManager;
use Bitrix\Security\Mfa\Otp;

final class MessengerConfirmationAvailabilityPolicy
{
	public const OPTION_ENABLED = 'messenger_confirmation_enabled';

	private const DEFAULT_ENABLED = 'Y';
	private const MODULE_ID = 'intranet';

	public function isEnabled(): bool
	{
		return Option::get(self::MODULE_ID, self::OPTION_ENABLED, self::DEFAULT_ENABLED) !== 'N';
	}

	public function isAvailable(int $userId): bool
	{
		if ($userId <= 0)
		{
			return false;
		}

		try
		{
			// Login availability must not load messenger modules.
			if (
				!$this->isEnabled()
				|| !ModuleManager::isModuleInstalled('im')
				|| !ModuleManager::isModuleInstalled('imbot')
			)
			{
				return false;
			}

			$otp = Loader::includeModule('security') ? Otp::getByUser($userId) : null;

			return (new SecondFactorCodeProvider())->hasCodeFor($otp)
				&& !$otp->isAttemptsReached()
				&& Loader::includeModule('pull')
				&& \CPullOptions::GetQueueServerStatus();
		}
		catch (\Throwable)
		{
			return false;
		}
	}
}
