<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Registration;

final readonly class RegistrationResponse
{
	public function __construct(
		public string $iss,
		public string $publicKey,
		public string $portalId,
		public int $ttlSeconds,
	) {
	}
}
