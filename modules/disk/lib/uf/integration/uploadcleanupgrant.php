<?php

declare(strict_types=1);

namespace Bitrix\Disk\Uf\Integration;

use Bitrix\Main\Security\Random;
use Bitrix\Main\Security\Sign\TimeSigner;
use Bitrix\Main\Web\Json;

final class UploadCleanupGrant
{
	public const VERSION = 2;
	public const PURPOSE = 'disk.uf.upload.cleanup';
	public const CONTROLLER = 'disk.uf.integration.diskUploaderController';
	public const TTL = '+3 hours';

	private const PAYLOAD_KEYS = ['c', 'd', 'exp', 'f', 'n', 'p', 'u', 'v'];

	public static function issueFor(int $fileId, int $userId, int $folderId): string
	{
		$expiresAt = time() + 3 * 60 * 60;
		$value = Json::encode([
			'v' => self::VERSION,
			'p' => self::PURPOSE,
			'c' => self::CONTROLLER,
			'f' => $fileId,
			'u' => $userId,
			'd' => $folderId,
			'n' => Random::getString(64, true),
			'exp' => $expiresAt,
		]);

		return (new TimeSigner())->sign($value, $expiresAt, self::PURPOSE);
	}

	public static function matches(string $token, int $fileId, int $userId, int $folderId): bool
	{
		$payload = self::openV2($token);

		return
			$payload !== null
			&& $payload['f'] === $fileId
			&& $payload['u'] === $userId
			&& $payload['d'] === $folderId;
	}

	/**
	 * @return array{v: int, p: string, c: string, f: int, u: int, d: int, n: string, exp: int}|null
	 */
	public static function openV2(string $token): ?array
	{
		if ($token === '')
		{
			return null;
		}

		try
		{
			$signer = new TimeSigner();
			$value = $signer->unsign($token, self::PURPOSE);
			$payload = Json::decode($value);
			[$timedValue] = $signer->unpack($token);
			[, $signedExpiresAt] = $signer->unpack($timedValue);
			$signedExpiresAt = (int)$signedExpiresAt;
		}
		catch (\Throwable)
		{
			return null;
		}

		if (!is_array($payload))
		{
			return null;
		}

		$keys = array_keys($payload);
		sort($keys);
		if ($keys !== self::PAYLOAD_KEYS)
		{
			return null;
		}

		if (
			!is_int($payload['v'])
			|| !is_string($payload['p'])
			|| !is_string($payload['c'])
			|| !is_int($payload['f'])
			|| !is_int($payload['u'])
			|| !is_int($payload['d'])
			|| !is_string($payload['n'])
			|| !is_int($payload['exp'])
			|| $payload['v'] !== self::VERSION
			|| $payload['p'] !== self::PURPOSE
			|| $payload['c'] !== self::CONTROLLER
			|| $payload['f'] <= 0
			|| $payload['u'] <= 0
			|| $payload['d'] <= 0
			|| strlen($payload['n']) !== 64
			|| $payload['exp'] <= time()
			|| $payload['exp'] !== $signedExpiresAt
		)
		{
			return null;
		}

		return $payload;
	}
}
