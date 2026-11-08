<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Service\Otp;

final class AuthenticationMethod
{
	public const PUSH = 'push';
	public const SMS = 'sms';
	public const EMAIL = 'email';
	public const RECOVERY_CODES = 'recoveryCodes';
	public const APPLICATION_OFFLINE_CODE = 'applicationOfflineCode';
	public const OTP = 'otp';

	private const SUPPORTED_METHODS = [
		self::PUSH,
		self::SMS,
		self::EMAIL,
		self::RECOVERY_CODES,
		self::APPLICATION_OFFLINE_CODE,
		self::OTP,
	];

	public static function getAnalyticsValue(string $method): ?string
	{
		return in_array($method, self::SUPPORTED_METHODS, true) ? 'method_' . $method : null;
	}
}
