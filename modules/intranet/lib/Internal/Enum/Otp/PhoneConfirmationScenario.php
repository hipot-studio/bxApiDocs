<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Enum\Otp;

use Bitrix\Intranet\Public\Contract\Otp\ConfirmationCallChannelInterface;

enum PhoneConfirmationScenario: string
{
	case Setup = ConfirmationCallChannelInterface::SCENARIO_PORTAL_2FA_SETUP;
	case Login = ConfirmationCallChannelInterface::SCENARIO_PORTAL_2FA_LOGIN;
}
