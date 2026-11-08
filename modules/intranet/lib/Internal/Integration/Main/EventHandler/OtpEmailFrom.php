<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\Main\EventHandler;

use Bitrix\Intranet\Internal\Service\Otp\OtpEmailFromService;

final class OtpEmailFrom
{
	private const EVENT_NAMES = [
		'USER_OTP_AUTH_CODE',
		'USER_OTP_EMAIL_CONFIRM',
	];

	public static function addToMailEvent(mixed $eventName, mixed $siteId, mixed &$fields): bool
	{
		if (!is_string($eventName) || !in_array($eventName, self::EVENT_NAMES, true))
		{
			return true;
		}

		$emailFrom = OtpEmailFromService::getConfigured();
		if ($emailFrom === null)
		{
			return true;
		}

		if (!is_array($fields))
		{
			$fields = [];
		}

		$fields['OTP_EMAIL_FROM'] = $emailFrom;

		return true;
	}
}
