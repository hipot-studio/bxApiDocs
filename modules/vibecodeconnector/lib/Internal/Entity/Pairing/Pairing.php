<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Entity\Pairing;

use Bitrix\Vibecodeconnector\Internal\Service\PublicKey\PublicKeySource;

final readonly class Pairing
{
	public function __construct(
		public string $iss,
		public string $publicKey,
		public string $portalId,
		public string $endpointUrl,
		public int $fetchedAt,
		public int $expiresAt,
		public int $publicKeyTtl,
		public PublicKeySource $keySource,
	) {
	}

	public function isExpired(?int $now = null): bool
	{
		return ($now ?? time()) >= $this->expiresAt;
	}
}
