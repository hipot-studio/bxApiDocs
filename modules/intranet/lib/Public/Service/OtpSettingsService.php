<?php

declare(strict_types = 1);

namespace Bitrix\Intranet\Public\Service;

use Bitrix\Intranet\Internal\Enum\Otp\PromoteMode;
use Bitrix\Intranet\Internal\Integration\Main\VerifyPhoneService;
use Bitrix\Intranet\Internal\Integration\Security\OtpSettings;
use Bitrix\Intranet\Internal\Service\Otp\MobilePush;
use Bitrix\Intranet\Repository\UserRepository;

class OtpSettingsService
{
	public function __construct(
		private readonly OtpSettings $otp,
	)
	{
	}

	public function isEnabled(): bool
	{
		return $this->otp->isEnabled();
	}

	public function isEnabledForUser(int $userId): bool
	{
		return $this->otp->getPersonalSettingsByUserId($userId)?->isActivated() ?? false;
	}

	public function isMandatory(): bool
	{
		return $this->otp->isMandatoryUsing();
	}

	public function isPushOtpHighPromote(): bool
	{
		return $this->otp->isDefaultTypePush() && MobilePush::createByDefault()->getPromoteMode() === PromoteMode::High;
	}

	public function canLoginBySms(int $userId): bool
	{
		$user = (new UserRepository())->getUserById($userId);

		if ($user)
		{
			return (new VerifyPhoneService($user))->canLoginBySms();
		}

		return false;
	}

	public function isRecoveryCodesEnabled(): bool
	{
		return (new OtpSettings())->isRecoveredCodesEnabled();
	}
}
