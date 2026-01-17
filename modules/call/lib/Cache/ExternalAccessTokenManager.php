<?php

namespace Bitrix\Call\Cache;

use Bitrix\Main\Data\Cache;

class ExternalAccessTokenManager
{
	public const TOKEN_TTL = 24 * 3600; // 24 hours
	public const CACHE_DIR = '/call/track_tokens/';

	/**
	 * Generates temporary access token for external services
	 * @param int $trackId
	 * @param int $callId
	 * @return string
	 */
	public static function generateToken(int $trackId, int $callId): string
	{
		$token = md5(uniqid('track_' . $trackId . '_', true));

		$cache = Cache::createInstance();
		$cacheId = static::getCacheId($token);
		$cacheDir = static::CACHE_DIR;

		$tokenData = [
			'track_id' => $trackId,
			'call_id' => $callId,
			'created_at' => time(),
		];

		$cache->startDataCache(static::TOKEN_TTL, $cacheId, $cacheDir);
		$cache->endDataCache($tokenData);

		return $token;
	}

	/**
	 * Validates temporary access token
	 * @param string $token
	 * @return array|null - token data or null if token is invalid
	 */
	public static function validateToken(string $token): ?array
	{
		$cache = Cache::createInstance();
		$cacheId = static::getCacheId($token);
		$cacheDir = static::CACHE_DIR;

		if ($cache->initCache(static::TOKEN_TTL, $cacheId, $cacheDir))
		{
			return $cache->getVars();
		}

		return null;
	}

	/**
	 * Revokes access token (one-time use)
	 * @param string $token
	 */
	public static function revokeToken(string $token): void
	{
		$cache = Cache::createInstance();
		$cacheId = static::getCacheId($token);
		$cacheDir = static::CACHE_DIR;

		$cache->clean($cacheId, $cacheDir);
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
