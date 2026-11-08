<?php

declare(strict_types = 1);

namespace Bitrix\Intranet\Internal\Integration\Security;

use Bitrix\Intranet\Internal\Service\Otp\TrustedDeviceFingerprint;
use Bitrix\Main\Loader;
use Bitrix\Main\SystemException;
use Bitrix\Security\Mfa\Otp;

/**
 * Compares a fingerprint against the current Push OTP secret of a user.
 *
 * The secret never leaves this class. An empty secret never reaches the
 * comparison: its fingerprint is a constant derived from the context string
 * alone, so anyone could compute it.
 */
class PushOtpSecretMatcher
{
	public function __construct(
		private readonly TrustedDeviceFingerprint $fingerprint = new TrustedDeviceFingerprint(),
	)
	{
		if (!Loader::includeModule('security'))
		{
			throw new SystemException('Module security is not installed');
		}
	}

	public function matches(int $userId, string $given): bool
	{
		$secret = $this->readSecret($userId);
		if ($secret === '')
		{
			return false;
		}

		return $this->fingerprint->matches($this->fingerprint->calculate($secret), $given);
	}

	protected function readSecret(int $userId): string
	{
		return $userId > 0 ? Otp::getByUser($userId)->getSecret() : '';
	}
}
