<?php

declare(strict_types = 1);

namespace Bitrix\Intranet\Internal\Service\Otp;

use Bitrix\Main\Config\Option;

class MandatoryOtpPolicy
{
	private const MODULE_ID = 'intranet';
	private const HIDDEN_OPTION = 'mandatory_otp_setting_hidden';

	public function isMandatorySettingShownOnPortal(): bool
	{
		return !$this->isHiddenByVendor();
	}

	public function isTransitionPeriodOwnedByPortal(): bool
	{
		return !$this->isHiddenByVendor();
	}

	private function isHiddenByVendor(): bool
	{
		return Option::get(self::MODULE_ID, self::HIDDEN_OPTION) === 'Y';
	}
}
