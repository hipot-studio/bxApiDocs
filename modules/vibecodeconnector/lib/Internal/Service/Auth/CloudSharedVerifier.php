<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Auth;

use Bitrix\Main\Web\JWT;
use Bitrix\Vibecodeconnector\Internal\Exception\IncomingJwtInvalidException;
use Bitrix\Vibecodeconnector\Internal\Integration\Socialservices\PortalNetworkId;
use Bitrix\Vibecodeconnector\Internal\Service\Diagnostic\CloudSharedKeyLog;
use Bitrix\Vibecodeconnector\Internal\Service\PublicKey\CloudSharedKeyProvisioner;
use Bitrix\Vibecodeconnector\Internal\Service\PublicKey\CloudSharedKeyStore;
use \Bitrix\Main\Service\MicroService;

/**
 * Trust slot for the key shared by the Vibecode service across cloud portals.
 *
 * Contract: verify() may be called only after isApplicable() returned true. It checks the
 * signature and the claims, but not the portal type, and obtains a missing key on demand —
 * so on a box portal with a filled network id a direct call would accept the token.
 */
final class CloudSharedVerifier
{
	private const ISS = 'vibecode';
	private const ALGORITHM = 'ES256';
	private const VERIFY_LEEWAY_SECONDS = 60;
	// Large enough to switch JWT time-claim checks off when only the signature verdict is needed.
	private const IGNORE_TIME_LEEWAY_SECONDS = 315360000;

	public function __construct(
		private readonly CloudSharedKeyStore $keyStore = new CloudSharedKeyStore(),
		private readonly CloudSharedKeyProvisioner $keyProvisioner = new CloudSharedKeyProvisioner(),
		private readonly PortalNetworkId $portalNetworkId = new PortalNetworkId(),
	) {
	}

	/**
	 * A missing key is not a reason to reject the slot: verify() obtains it on demand.
	 */
	public function isApplicable(string $iss): bool
	{
		return str_starts_with($iss, self::ISS) && $this->isSlotAvailable();
	}

	/**
	 * Whether the slot serves this portal at all, regardless of the stored key. This is the
	 * working state for the admin pages: the key arrives on the first incoming request.
	 */
	public function isSlotAvailable(): bool
	{
		return MicroService\Client::getPortalType() === MicroService\Client::TYPE_BITRIX24
			&& $this->portalNetworkId->get() !== null
		;
	}

	/**
	 * Whether the slot already holds a key. Read by the admin pages for the key status line;
	 * it is not a readiness check — see AvailabilityService::isReady().
	 */
	public function isConfigured(): bool
	{
		return $this->isSlotAvailable() && $this->keyStore->get() !== '';
	}

	public function verify(string $jwt): object
	{
		$networkId = $this->portalNetworkId->get();
		if ($networkId === null)
		{
			throw new IncomingJwtInvalidException('Cloud-shared slot is not configured');
		}

		$publicKey = $this->keyStore->get();
		if ($publicKey === '')
		{
			$publicKey = $this->keyProvisioner->provision(CloudSharedKeyLog::REASON_EMPTY);
		}

		if ($publicKey === '')
		{
			throw new IncomingJwtInvalidException('Cloud-shared slot is not configured');
		}

		try
		{
			$payload = $this->decode($jwt, $publicKey, self::VERIFY_LEEWAY_SECONDS);
		}
		catch (IncomingJwtInvalidException $verifyError)
		{
			// Signature mismatch is the only rotation signal we get: refetch once and retry.
			if (!$this->isSignatureMismatch($jwt, $publicKey))
			{
				throw $verifyError;
			}

			$rotatedKey = $this->keyProvisioner->provision(CloudSharedKeyLog::REASON_SIGNATURE, $publicKey);
			if ($rotatedKey === '' || $rotatedKey === $publicKey)
			{
				throw $verifyError;
			}

			$payload = $this->decode($jwt, $rotatedKey, self::VERIFY_LEEWAY_SECONDS);
		}

		$this->assertClaims($payload, $networkId);

		return $payload;
	}

	/**
	 * JWT::decode reports a bad signature and stale time claims with the same exception type,
	 * so a second pass with time checks off tells the two apart: only the former means the
	 * stored key may be outdated.
	 */
	private function isSignatureMismatch(string $jwt, string $publicKey): bool
	{
		try
		{
			$this->decode($jwt, $publicKey, self::IGNORE_TIME_LEEWAY_SECONDS);

			return false;
		}
		catch (IncomingJwtInvalidException)
		{
			return true;
		}
	}

	private function decode(string $jwt, string $publicKey, int $leeway): object
	{
		try
		{
			$oldLeeway = JWT::$leeway;
			JWT::$leeway = $leeway;

			return JWT::decode($jwt, $publicKey, [self::ALGORITHM]);
		}
		catch (\Throwable $e)
		{
			throw new IncomingJwtInvalidException('Cloud-shared verification failed: ' . $e->getMessage(), 0, $e);
		}
		finally
		{
			JWT::$leeway = $oldLeeway;
		}
	}

	private function assertClaims(object $payload, string $networkId): void
	{
		if (!is_string($payload->iss ?? null) || !str_starts_with($payload->iss, self::ISS))
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
}
