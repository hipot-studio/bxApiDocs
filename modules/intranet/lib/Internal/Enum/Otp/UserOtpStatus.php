<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Enum\Otp;

enum UserOtpStatus: string
{
	case Enabled = 'enabled';
	case UpdateRequired = 'update_required';
	case UpdateRecommended = 'update_recommended';
	case EnableRequired = 'enable_required';
	case Disabled = 'disabled';
}
