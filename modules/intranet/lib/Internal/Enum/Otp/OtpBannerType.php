<?php

declare(strict_types = 1);

namespace Bitrix\Intranet\Internal\Enum\Otp;

enum OtpBannerType: int
{
	case DISABLED_ALL_2FA = 1;
	case MANDATORY_2FA = 2;
	case ENABLED_OLD_2FA = 3;
	case ENABLED_OLD_2FA_AND_NEED_PUSH_2FA = 4;
	case ONLY_ADMIN_ENABLED_NEW_2FA = 5;
}
