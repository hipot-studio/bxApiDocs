<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Rest;

use Bitrix\Vibecodeconnector\Internal\Service\Availability\PortalReadiness;

final class AccessGrantService
{
	/** @var array<string, bool> */
	private array $verifiedTokens = [];

	public function __construct(
		private readonly PortalReadiness $portalReadiness = new PortalReadiness(),
		private readonly OriginTokenVerifier $originTokenVerifier = new OriginTokenVerifier(),
	) {
	}

	public function isGranted(string $originToken): bool
	{
		if ($originToken === '')
		{
			return false;
		}

		if (!$this->portalReadiness->isReady())
		{
			return false;
		}

		return $this->verifyOnce($originToken);
	}

	private function verifyOnce(string $token): bool
	{
		$cacheKey = hash('sha256', $token);
		if (!array_key_exists($cacheKey, $this->verifiedTokens))
		{
			$this->verifiedTokens[$cacheKey] = $this->originTokenVerifier->verifyCowork($token);
		}

		return $this->verifiedTokens[$cacheKey];
	}
}
