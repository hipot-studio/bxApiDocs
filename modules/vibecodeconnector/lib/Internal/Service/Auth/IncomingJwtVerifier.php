<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Auth;

use Bitrix\Main\Web\JWT;
use Bitrix\Vibecodeconnector\Internal\Entity\Pairing\Pairing;
use Bitrix\Vibecodeconnector\Internal\Exception\IncomingJwtInvalidException;
use Bitrix\Vibecodeconnector\Internal\Exception\PublicKeyFetchFailedException;
use Bitrix\Vibecodeconnector\Internal\Repository\Pairing\PairingRepository;
use Bitrix\Vibecodeconnector\Internal\Service\PublicKey\KeyRefreshThrottle;
use Bitrix\Vibecodeconnector\Internal\Service\PublicKey\PairingKeyRefresher;

final class IncomingJwtVerifier
{
	private const ALGORITHM = 'ES256';
	private const VERIFY_LEEWAY_SECONDS = 60;
	private const REQUIRE_AUDIENCE_MATCH = true;
	private const REFRESH_ATTEMPT_OPTION_PREFIX = 'pairing_key_attempt_at_';

	public function __construct(
		private readonly PairingRepository $repository = new PairingRepository(),
		private readonly CloudSharedVerifier $cloudSharedVerifier = new CloudSharedVerifier(),
		private readonly PairingKeyRefresher $keyRefresher = new PairingKeyRefresher(),
	) {
	}

	public function verify(string $jwt): object
	{
		if ($jwt === '')
		{
			throw new IncomingJwtInvalidException('Token is empty');
		}

		$iss = $this->extractIss($jwt);
		if ($iss === null)
		{
			throw new IncomingJwtInvalidException('Token has no iss claim');
		}

		$pairing = $this->repository->findByIss($iss);

		if ($pairing === null && $this->cloudSharedVerifier->isApplicable($iss))
		{
			return $this->cloudSharedVerifier->verify($jwt);
		}

		if ($pairing === null)
		{
			throw new IncomingJwtInvalidException("No pairing for iss='{$iss}'");
		}

		if ($pairing->isExpired())
		{
			$throttle = new KeyRefreshThrottle(optionName: self::REFRESH_ATTEMPT_OPTION_PREFIX . md5($iss));
			if (!$throttle->isAllowed())
			{
				throw new IncomingJwtInvalidException("Public key refetch for iss='{$iss}' is throttled");
			}
			$throttle->markAttempt();

			try
			{
				$pairing = $this->keyRefresher->refresh($iss);
			}
			catch (PublicKeyFetchFailedException $e)
			{
				throw new IncomingJwtInvalidException(
					"Public key refetch for iss='{$iss}' failed: " . $e->getMessage(),
					0,
					$e,
				);
			}
		}

		return $this->verifySignatureAndClaims($jwt, $pairing);
	}

	private function verifySignatureAndClaims(string $jwt, Pairing $pairing): object
	{
		try
		{
			$oldLeeway = JWT::$leeway;
			JWT::$leeway = self::VERIFY_LEEWAY_SECONDS;

			$payload = JWT::decode($jwt, $pairing->publicKey, [self::ALGORITHM]);
		}
		catch (\Throwable $e)
		{
			throw new IncomingJwtInvalidException(
				'Signature verification failed: ' . $e->getMessage(),
				0,
				$e,
			);
		}
		finally
		{
			JWT::$leeway = $oldLeeway;
		}

		if (($payload->iss ?? null) !== $pairing->iss)
		{
			throw new IncomingJwtInvalidException('Invalid issuer');
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
				throw new IncomingJwtInvalidException('Token not yet valid');
			}
		}

		if (self::REQUIRE_AUDIENCE_MATCH && ($payload->aud ?? null) !== $pairing->portalId)
		{
			throw new IncomingJwtInvalidException('Invalid audience');
		}

		return $payload;
	}

	private function extractIss(string $jwt): ?string
	{
		$parts = explode('.', $jwt);
		if (count($parts) !== 3)
		{
			return null;
		}

		$padded = strtr($parts[1], '-_', '+/');
		$pad = strlen($padded) % 4;
		if ($pad > 0)
		{
			$padded .= str_repeat('=', 4 - $pad);
		}

		$json = base64_decode($padded, true);
		if ($json === false)
		{
			return null;
		}

		$decoded = json_decode($json, true);
		if (!is_array($decoded))
		{
			return null;
		}

		$iss = $decoded['iss'] ?? null;

		return is_string($iss) && $iss !== '' ? $iss : null;
	}
}
