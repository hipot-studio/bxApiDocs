<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Auth;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Web\JWT;
use Bitrix\Vibecodeconnector\Internal\Exception\IncomingJwtInvalidException;
use Bitrix\Vibecodeconnector\Internal\Service\PublicKey\CloudSharedKeyStore;
use \Bitrix\Main\Service\MicroService;

final class CloudSharedVerifier
{
	private const ISS = 'vibecode';
	private const ALGORITHM = 'ES256';
	private const VERIFY_LEEWAY_SECONDS = 60;

	public function __construct(
		private readonly CloudSharedKeyStore $keyStore = new CloudSharedKeyStore(),
	) {
	}

	public function isApplicable(string $iss): bool
	{
		return $iss === self::ISS && $this->isConfigured();
	}

	public function isConfigured(): bool
	{
		return MicroService\Client::getPortalType() === MicroService\Client::TYPE_BITRIX24
			&& $this->keyStore->get() !== ''
			&& $this->loadNetworkId() !== ''
		;
	}

	public function verify(string $jwt): void
	{
		$publicKey = $this->keyStore->get();
		$networkId = $this->loadNetworkId();

		if ($publicKey === '' || $networkId === '')
		{
			throw new IncomingJwtInvalidException('Cloud-shared slot is not configured');
		}

		try
		{
			$oldLeeway = JWT::$leeway;
			JWT::$leeway = self::VERIFY_LEEWAY_SECONDS;

			$payload = JWT::decode($jwt, $publicKey, [self::ALGORITHM]);
		}
		catch (\Throwable $e)
		{
			throw new IncomingJwtInvalidException('Cloud-shared verification failed: ' . $e->getMessage(), 0, $e);
		}
		finally
		{
			JWT::$leeway = $oldLeeway;
		}

		if (($payload->iss ?? null) !== self::ISS)
		{
			throw new IncomingJwtInvalidException('Invalid issuer (cloud-shared)');
		}

		if (!is_int($payload->iat ?? null))
		{
			throw new IncomingJwtInvalidException('Missing or invalid issued-at claim');
		}

		if (!is_int($payload->exp ?? null))
		{
			throw new IncomingJwtInvalidException('Missing or invalid expiration claim');
		}

		if (isset($payload->nbf))
		{
			if (!is_int($payload->nbf))
			{
				throw new IncomingJwtInvalidException('Invalid not-before claim');
			}
			if ($payload->nbf > time() + self::VERIFY_LEEWAY_SECONDS)
			{
				throw new IncomingJwtInvalidException('Token not yet valid (cloud-shared)');
			}
		}

		if (($payload->aud ?? null) !== $networkId)
		{
			throw new IncomingJwtInvalidException('Invalid audience (cloud-shared)');
		}
	}

	private function loadNetworkId(): string
	{
		return (string)Option::get('socialservices', 'bitrix24net_id', '');
	}
}
