<?php

namespace Bitrix\Security\Mfa;

enum OtpType: string
{
	case Hotp = 'hotp';
	case Totp = 'totp';
	case Push = 'push';

	public function algorithm(): string
	{
		return match($this)
		{
			OtpType::Hotp => '\Bitrix\Main\Security\Mfa\HotpAlgorithm',
			OtpType::Totp, OtpType::Push => '\Bitrix\Main\Security\Mfa\TotpAlgorithm',
		};
	}
}
