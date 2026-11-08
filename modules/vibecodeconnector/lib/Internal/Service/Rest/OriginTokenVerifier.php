<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Rest;

use Bitrix\Vibecodeconnector\Internal\Exception\IncomingJwtInvalidException;
use Bitrix\Vibecodeconnector\Internal\Service\Auth\IncomingJwtVerifier;
use Bitrix\Vibecodeconnector\Internal\Service\Provisioning\EntryPoint;

final class OriginTokenVerifier
{
	public function __construct(
		private readonly IncomingJwtVerifier $jwtVerifier = new IncomingJwtVerifier(),
	) {
	}

	public function verifyCowork(string $token): bool
	{
		try
		{
			$payload = $this->jwtVerifier->verify($token);
		}
		catch (IncomingJwtInvalidException)
		{
			return false;
		}

		return EntryPoint::vibecode($payload->iss, is_string($payload->origin ?? null) ? $payload->origin : null)->isCowork();
	}
}
