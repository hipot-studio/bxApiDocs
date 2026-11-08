<?php

declare(strict_types = 1);

namespace Bitrix\Intranet\Internal\Service\Otp;

/**
 * Fingerprint of the Push OTP secret, a contract shared with mobile clients.
 *
 * The context string keeps this fingerprint distinct from any other hash of the
 * same secret. It is an independent constant, not derived from the REST method
 * name: renaming the method must not invalidate live devices. Changing the rule
 * requires a new version suffix and a period when both values are accepted.
 */
class TrustedDeviceFingerprint
{
	private const CONTEXT = 'intranet.otp.trusted-device.v1';
	private const ALGORITHM = 'sha256';

	public function calculate(string $rawSecret): string
	{
		return hash(self::ALGORITHM, self::CONTEXT . $rawSecret);
	}

	public function matches(string $expected, string $given): bool
	{
		return hash_equals($expected, mb_strtolower($given));
	}
}
