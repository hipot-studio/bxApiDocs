<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Public\Type\Otp;

enum OtpChannel: string
{
	case Phone = 'phone';
	case Email = 'email';
}
