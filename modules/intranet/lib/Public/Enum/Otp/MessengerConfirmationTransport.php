<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Public\Enum\Otp;

/**
 * Clients that can wait for a messenger confirmation. Each of them keeps the binding of its login attempt
 * under its own kernel session key, and the whole set of keys is known here alone.
 */
enum MessengerConfirmationTransport: string
{
	case Web = 'web';
	case Mobile = 'mobile';

	public function getSessionKey(): string
	{
		return match ($this)
		{
			self::Web => 'INTRANET_OTP_MESSENGER_CONFIRMATION_BINDING',
			self::Mobile => 'MOBILE_OTP_MESSENGER_CONFIRMATION_BINDING',
		};
	}
}
