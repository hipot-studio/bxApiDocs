<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Service\Otp;

use Bitrix\Intranet\Internal\Enum\Otp\OtpScenario;
use Bitrix\Intranet\Internal\Enum\Otp\PromoteMode;
use Bitrix\Security\Mfa\OtpType;

class OtpScenarioResolver
{
	public function __construct(
		private readonly MobilePush $mobilePush,
	) {
	}

	public static function createByDefault(): self
	{
		return new self(MobilePush::createByDefault());
	}

	/**
	 * Whether OTP is available and enabled on the portal at all is a precondition of the caller.
	 */
	public function resolve(int $userId, OtpType $personalOtpType, bool $isPersonalOtpInitialized): OtpScenario
	{
		if ($isPersonalOtpInitialized)
		{
			return $personalOtpType === OtpType::Push ? OtpScenario::Push : OtpScenario::Legacy;
		}

		if ($this->mobilePush->isLegacyOtpAllowedByUserId($userId))
		{
			return OtpScenario::Legacy;
		}

		if ($this->mobilePush->isDefault())
		{
			return OtpScenario::Push;
		}

		return $this->mobilePush->getPromoteMode()->isGreaterOrEqual(PromoteMode::Low)
			? OtpScenario::Push
			: OtpScenario::Legacy;
	}
}
