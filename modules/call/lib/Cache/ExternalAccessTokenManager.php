<?php

namespace Bitrix\Call\Cache;

use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Data\Storage\PersistentStorageInterface;

class ExternalAccessTokenManager
{
	private const TOKEN_TTL = 24 * 3600; // 24 hours

	/**
	 * Generates temporary access token for external services
	 * @param int $trackId
	 * @param int $callId
	 * @return string
	 */
	public static function generateToken(int $trackId, int $callId): string
	{
		$token = md5(uniqid('track_' . $trackId . '_', true));
		$tokenData = [
			'track_id' => $trackId,
			'call_id' => $callId,
			'created_at' => time(),
		];

		ServiceLocator::getInstance()
			->get(PersistentStorageInterface::class)
			->set($token, $tokenData, static::TOKEN_TTL)
		;

		return $token;
	}

	/**
	 * Validates temporary access token
	 * @param string $token
	 * @return array|null - token data or null if token is invalid
	 */
	public static function validateToken(string $token): ?array
	{
		return ServiceLocator::getInstance()
			->get(PersistentStorageInterface::class)
			->get($token, null)
		;
	}

	/**
	 * Revokes access token (one-time use)
	 * @param string $token
	 */
	public static function revokeToken(string $token): void
	{
		ServiceLocator::getInstance()
			->get(PersistentStorageInterface::class)
			->delete($token)
		;
	}

	/**
	 * Generates cache ID for token
	 * @param string $token
	 * @return string
	 */
	protected static function getCacheId(string $token): string
	{
		return 'call_track_external_token_' . $token;
	}
}
