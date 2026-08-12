<?php

namespace Bitrix\StaffTrack\Public\Services;

use Bitrix\Main\Data\Cache;
use Bitrix\Main\ORM\Fields\CryptoField;
use Bitrix\Main\Security\Cipher;
use Bitrix\Main\Security\SecurityException;
use Bitrix\StaffTrack\Internal\Geo\GeohashKey;
use Bitrix\StaffTrack\Internal\Repository\AddressRepository;

class AddressCache
{
	private const CACHE_TTL = 259200;
	private const CACHE_DIR = '/stafftrack/address/';
	private const ENC_PREFIX = 'enc:';

	public static function get(string $geohash): ?string
	{
		$cacheId = self::getCacheId($geohash);
		$cache = Cache::createInstance();

		if ($cache->initCache(self::CACHE_TTL, $cacheId, self::CACHE_DIR))
		{
			$data = $cache->getVars();
			$stored = $data['address'] ?? null;

			return $stored === null ? null : self::decryptAddress((string)$stored);
		}

		$row = AddressRepository::findResolvedByGeohash($geohash);

		if ($row === null || ($row['ADDRESS'] ?? '') === '')
		{
			return null;
		}

		AddressRepository::touchLastUsed($geohash);

		$address = (string)$row['ADDRESS'];
		$encoded = self::encryptAddress($address);
		if ($encoded !== null && $cache->startDataCache(self::CACHE_TTL, $cacheId, self::CACHE_DIR))
		{
			$cache->endDataCache(['address' => $encoded]);
		}

		return $address;
	}

	public static function set(string $geohash, string $address): void
	{
		self::setByKey(GeohashKey::hash($geohash), $address);
	}

	public static function setByKey(string $geohashKey, string $address): void
	{
		$cacheId = 'addr_' . $geohashKey;
		$cache = Cache::createInstance();

		$encoded = self::encryptAddress($address);
		if ($encoded === null)
		{
			return;
		}

		if ($cache->startDataCache(self::CACHE_TTL, $cacheId, self::CACHE_DIR))
		{
			$cache->endDataCache(['address' => $encoded]);
		}
	}

	private static function getCacheId(string $geohash): string
	{
		return 'addr_' . GeohashKey::hash($geohash);
	}

	private static function encryptAddress(string $address): ?string
	{
		if ($address === '' || !CryptoField::cryptoAvailable())
		{
			return $address;
		}

		try
		{
			$cipher = new Cipher();
			$encrypted = $cipher->encrypt($address, (string)CryptoField::getDefaultKey());
		}
		catch (SecurityException)
		{
			return null;
		}

		return self::ENC_PREFIX . base64_encode($encrypted);
	}

	private static function decryptAddress(string $stored): ?string
	{
		if (!str_starts_with($stored, self::ENC_PREFIX))
		{
			// plaintext before migration
			// TODO:: remove this condition after 3 days of successful testing on real data
			return $stored;
		}

		$payload = base64_decode(substr($stored, strlen(self::ENC_PREFIX)), true);
		if ($payload === false)
		{
			return null;
		}

		try
		{
			return (new Cipher())?->decrypt($payload, (string)CryptoField::getDefaultKey());
		}
		catch (SecurityException $e)
		{
			return null;
		}
	}
}