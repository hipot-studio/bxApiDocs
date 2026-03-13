<?php

declare(strict_types = 1);

namespace Bitrix\Intranet\Public\Service;

use Bitrix\Intranet\Internal\Enum\Otp\PromoteMode;
use Bitrix\Intranet\Internal\Integration\Security\OtpSettings;
use Bitrix\Intranet\Internal\Service\Otp\MobilePush;

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
		return $this->otp->getPersonalSettingsByUserId($userId)?->isActivated();
	}

	public function isMandatory(): bool
	{
		return $this->otp->isMandatoryUsing();
	}

	public function isPushOtpHighPromote(): bool
	{
		return $this->otp->isDefaultTypePush() && MobilePush::createByDefault()->getPromoteMode() === PromoteMode::High;
	}
}
