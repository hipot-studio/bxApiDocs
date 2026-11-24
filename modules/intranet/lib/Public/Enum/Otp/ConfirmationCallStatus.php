<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Public\Enum\Otp;

enum ConfirmationCallStatus: string
{
	case Pending = 'pending';
	case Confirmed = 'confirmed';
	case Expired = 'expired';
	case Unavailable = 'unavailable';
}
