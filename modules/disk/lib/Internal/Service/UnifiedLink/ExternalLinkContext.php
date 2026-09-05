<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\UnifiedLink;

use Bitrix\Disk\ExternalLink;
use Bitrix\Disk\File;
use Bitrix\Main\Security\Sign\BadSignatureException;
use Bitrix\Main\Security\Sign\Signer;
use Bitrix\Main\Web\Json;

final class ExternalLinkContext
{
	private const PARAMETER_NAME = 'externalLinkContext';
	private const SIGNATURE_SALT = 'disk_external_link_context_v1';

	public static function getParameterName(): string
	{
		return self::PARAMETER_NAME;
	}

	public static function create(
		ExternalLink $externalLink,
		File $file,
		?int $attachedId = null,
		?int $versionId = null,
	): string
	{
		$realFile = $file->getRealObject();
		$payload = [
			'linkId' => (int)$externalLink->getId(),
			'objectId' => (int)$realFile->getId(),
			'uniqueCode' => (string)$realFile->getUniqueCode(),
			'attachedId' => $attachedId ?? 0,
			'versionId' => $versionId ?? 0,
		];

		return (new Signer())->sign(self::encodePayload($payload), self::SIGNATURE_SALT);
	}

	public static function resolve(
		string $marker,
		File $file,
		int $attachedId = 0,
		int $versionId = 0,
	): ?ExternalLink
	{
		$payload = self::decodeMarker($marker);
		if ($payload === null)
		{
			return null;
		}

		$realFile = $file->getRealObject();
		if (
			$payload['objectId'] !== (int)$realFile->getId()
			|| $payload['uniqueCode'] !== (string)$realFile->getUniqueCode()
			|| $payload['attachedId'] !== $attachedId
			|| $payload['versionId'] !== $versionId
		)
		{
			return null;
		}

		$externalLink = ExternalLink::loadById($payload['linkId']);
		if (!$externalLink instanceof ExternalLink || $externalLink->isExpired())
		{
			return null;
		}

		$linkObject = $externalLink->getObject();
		if (
			!$linkObject instanceof File
			|| (int)$linkObject->getRealObjectId() !== (int)$realFile->getId()
			|| (int)$externalLink->getVersionId() !== $versionId
		)
		{
			return null;
		}

		return $externalLink;
	}

	private static function encodePayload(array $payload): string
	{
		return rtrim(
			strtr(base64_encode(Json::encode($payload)), '+/', '-_'),
			'=',
		);
	}

	private static function decodeMarker(string $marker): ?array
	{
		try
		{
			$encodedPayload = (new Signer())->unsign($marker, self::SIGNATURE_SALT);
		}
		catch (BadSignatureException)
		{
			return null;
		}

		$payload = self::decodePayload($encodedPayload);
		if (
			$payload === null
			|| !isset(
				$payload['linkId'],
				$payload['objectId'],
				$payload['uniqueCode'],
				$payload['attachedId'],
				$payload['versionId'],
			)
			|| !is_int($payload['linkId'])
			|| !is_int($payload['objectId'])
			|| !is_string($payload['uniqueCode'])
			|| !is_int($payload['attachedId'])
			|| !is_int($payload['versionId'])
			|| $payload['linkId'] <= 0
			|| $payload['objectId'] <= 0
			|| $payload['uniqueCode'] === ''
			|| $payload['attachedId'] < 0
			|| $payload['versionId'] < 0
		)
		{
			return null;
		}

		return $payload;
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
}
