<?php

declare(strict_types=1);

namespace Bitrix\Disk\Integration\AiAssistant\Service;

use Bitrix\Main\Security\Sign\TimeSigner;
use Bitrix\Main\Web\Json;
use InvalidArgumentException;

final class FileDownloadGrant
{
	public const PURPOSE = 'disk.mcp.download';

	private const VERSION = 1;
	private const TTL_SECONDS = 300;
	private const PAYLOAD_KEYS = ['o', 'p', 'u', 'v'];

	/**
	 * Issues a short-lived grant for downloading the current file content.
	 *
	 * @param int $userId
	 * @param int $objectId
	 * @return array{token: string, expiresAt: int}
	 */
	public static function issue(int $userId, int $objectId): array
	{
		if ($userId <= 0 || $objectId <= 0)
		{
			throw new InvalidArgumentException('User and object ids must be greater than zero.');
		}

		$expiresAt = time() + self::TTL_SECONDS;
		$payload = self::encodePayload([
			'v' => self::VERSION,
			'p' => self::PURPOSE,
			'u' => $userId,
			'o' => $objectId,
		]);

		return [
			'token' => (new TimeSigner())->sign($payload, $expiresAt, self::PURPOSE),
			'expiresAt' => $expiresAt,
		];
	}

	/**
	 * Opens a valid short-lived download grant.
	 *
	 * @param string $token
	 * @return array{userId: int, objectId: int}|null
	 */
	public static function open(string $token): ?array
	{
		if ($token === '')
		{
			return null;
		}

		try
		{
			$encodedPayload = (new TimeSigner())->unsign($token, self::PURPOSE);
			$payload = self::decodePayload($encodedPayload);
		}
		catch (\Throwable)
		{
			return null;
		}

		if (!self::isValidPayload($payload))
		{
			return null;
		}

		return [
			'userId' => $payload['u'],
			'objectId' => $payload['o'],
		];
	}

	private static function encodePayload(array $payload): string
	{
		return rtrim(strtr(base64_encode(Json::encode($payload)), '+/', '-_'), '=');
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
		if ($payload === null)
		{
			return false;
		}

		$keys = array_keys($payload);
		sort($keys);
		if ($keys !== self::PAYLOAD_KEYS)
		{
			return false;
		}

		return is_int($payload['v'])
			&& $payload['v'] === self::VERSION
			&& is_string($payload['p'])
			&& $payload['p'] === self::PURPOSE
			&& is_int($payload['u'])
			&& $payload['u'] > 0
			&& is_int($payload['o'])
			&& $payload['o'] > 0;
	}
}
