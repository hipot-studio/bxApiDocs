<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Public\Service\Otp;

use Bitrix\Intranet\Internal\Enum\Otp\OtpScenario;
use Bitrix\Intranet\Internal\Integration\Security\OtpSettings;
use Bitrix\Intranet\Internal\Integration\Security\PersonalOtp;
use Bitrix\Intranet\Internal\Service\Otp\OtpScenarioResolver;
use Bitrix\Intranet\Repository\UserRepository;
use Bitrix\Main\Loader;

class OtpScenarioService
{
	/**
	 * Access control is out of the contract: the method does NOT check whether the current user
	 * is allowed to see the OTP state of $userId, the caller must do it before the call.
	 */
	public function isPushOtpScenario(int $userId): bool
	{
		if ($userId <= 0 || !Loader::includeModule('security'))
		{
			return false;
		}

		$otpSettings = new OtpSettings();

		if (!$otpSettings->isAvailable() || !$otpSettings->isEnabled())
		{
			return false;
		}

		$user = (new UserRepository())->getUserById($userId);

		if (!$user)
		{
			return false;
		}

		$personalOtp = new PersonalOtp($user);
		$scenario = OtpScenarioResolver::createByDefault()->resolve(
			$userId,
			$personalOtp->getType(),
			$personalOtp->isInitialized(),
		);

		return $scenario === OtpScenario::Push;
	}
}
