<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\HtmlViewer;

use Bitrix\Main\Security\Sign\BadSignatureException;
use Bitrix\Main\Security\Sign\TimeSigner;
use Bitrix\Main\Web\Json;

final class PublicDomainAccessTokenService
{
	private const TOKEN_TTL = '+5 minutes';
	private const SIGNATURE_SALT = 'disk_public_domain_access_v1';

	private readonly TimeSigner $signer;

	public function __construct(?TimeSigner $signer = null)
	{
		$this->signer = $signer ?? new TimeSigner();
	}

	public function createToken(PublicDomainAccessContext $context): string
	{
		$payload = [
			'version' => $context->getVersion(),
			'userId' => $context->getUserId(),
			'objectId' => $context->getObjectId(),
			'sourceType' => $context->getSourceType(),
			'sourceId' => $context->getSourceId(),
		];

		return $this->signer->sign(
			self::encodePayload($payload),
			self::TOKEN_TTL,
			self::SIGNATURE_SALT,
		);
	}

	public function decodeToken(string $token): ?PublicDomainAccessContext
	{
		if ($token === '')
		{
			return null;
		}

		try
		{
			$encodedPayload = $this->signer->unsign($token, self::SIGNATURE_SALT);
			$payload = self::decodePayload($encodedPayload);
		}
		catch (BadSignatureException|\Throwable)
		{
			return null;
		}

		if (!self::isValidPayload($payload))
		{
			return null;
		}

		return new PublicDomainAccessContext(
			$payload['userId'],
			$payload['objectId'],
			$payload['sourceType'],
			$payload['sourceId'],
		);
	}

	private static function encodePayload(array $payload): string
	{
		return rtrim(
			strtr(base64_encode(Json::encode($payload)), '+/', '-_'),
			'=',
		);
	}

	private static function decodePayload(string $encodedPayload): ?array
	{
		if (!preg_match('/^[A-Za-z0-9_-]+$/D', $encodedPayload))
		{
			return null;
		}

		$paddingLength = (4 - strlen($encodedPayload) % 4) % 4;
		$decodedPayload = base64_decode(
			strtr($encodedPayload, '-_', '+/') . str_repeat('=', $paddingLength),
			true,
		);
		if (!is_string($decodedPayload))
		{
			return null;
		}

		try
		{
			$payload = Json::decode($decodedPayload);
		}
		catch (\Throwable)
		{
			return null;
		}

		return is_array($payload) ? $payload : null;
	}

	private static function isValidPayload(?array $payload): bool
	{
		if (
			$payload === null
			|| count($payload) !== 5
			|| !isset(
				$payload['version'],
				$payload['userId'],
				$payload['objectId'],
				$payload['sourceType'],
				$payload['sourceId'],
			)
		)
		{
			return false;
		}

		return $payload['version'] === PublicDomainAccessContext::VERSION
			&& is_int($payload['userId'])
			&& $payload['userId'] > 0
			&& is_int($payload['objectId'])
			&& $payload['objectId'] > 0
			&& is_string($payload['sourceType'])
			&& in_array(
				$payload['sourceType'],
				[
					PublicDomainAccessContext::SOURCE_TYPE_FILE,
					PublicDomainAccessContext::SOURCE_TYPE_VERSION,
					PublicDomainAccessContext::SOURCE_TYPE_ATTACHED_OBJECT,
				],
				true,
			)
			&& is_int($payload['sourceId'])
			&& $payload['sourceId'] > 0
		;
	}
}
