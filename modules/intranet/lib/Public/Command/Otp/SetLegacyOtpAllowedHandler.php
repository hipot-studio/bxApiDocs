<?php

declare(strict_types = 1);

namespace Bitrix\Intranet\Public\Command\Otp;

use Bitrix\Intranet\Internal\Integration\Security\OtpSettings;
use Bitrix\Intranet\Internal\Service\Otp\MobilePush;
use Bitrix\Main\Loader;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Security\Mfa\OtpException;
use Bitrix\Security\Mfa\OtpType;

class SetLegacyOtpAllowedHandler
{
	public function __invoke(SetLegacyOtpAllowedCommand $command): Result
	{
		$result = new Result();
		$userId = $command->user->getId();

		if (!Loader::includeModule('security'))
		{
			return $result->addError(new Error('Module security is not installed'));
		}

		$mobilePush = MobilePush::createByDefault();

		if (!$mobilePush->isLegacyOtpAllowed())
		{
			return $result->addError(new Error('Legacy OTP is not allowed'));
		}

		if ($command->allowed)
		{
			$mobilePush->addLegacyOtpAllowedUserId($userId);
		}
		else
		{
			$mobilePush->removeLegacyOtpAllowedUserId($userId);
		}

		$personalSettings = (new OtpSettings())->getPersonalSettingsByUserId($command->user->getId());

		if ($command->allowed && $personalSettings?->isPushType() && !$personalSettings?->isActivated())
		{
			$personalSettings->setType(OtpType::Totp);

			try
			{
				$personalSettings->activate();
			}
			catch (OtpException)
			{}
		}

		return $result;
	}
}
