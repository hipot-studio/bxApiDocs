<?php

declare(strict_types = 1);

namespace Bitrix\Intranet\Internal\Enum\Otp;

enum OtpScenario
{
	case Push;
	case Legacy;
}
