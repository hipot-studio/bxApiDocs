<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Entity\Otp;

final class LoginAttemptContextField
{
	public const IP = 'ip';
	public const BROWSER = 'browser';
	public const DEVICE = 'device';
	public const ALL = [self::IP, self::BROWSER, self::DEVICE];
}
