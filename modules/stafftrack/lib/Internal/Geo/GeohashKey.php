<?php

namespace Bitrix\StaffTrack\Internal\Geo;

use Bitrix\Main\Config\Configuration;

/**
 * The name a cell of the map is stored under: an HMAC-SHA256 of its geohash keyed by a secret pepper,
 * 64 lowercase hex characters.
 *
 * The grid of geohashes is finite and a geohash is the place itself in a reversible encoding, so what
 * keeps the key from naming a place is the pepper and nothing else. Stretching the input defended only
 * the case of a leaked pepper - and whoever holds it holds the configuration of the portal, where the
 * key of the CryptoField over the coordinates lives too, so in that case the places are read directly
 * rather than enumerated. Against a leak of the database alone the two are equally silent.
 *
 * The pepper is derived from crypto.crypto_key, which every portal is given by its own install and
 * which the module never writes: a configuration file is shared by all the portals of a cloud server
 * and holds code rather than values, so a module writing there would freeze the values of one portal
 * into it for all of them. PURPOSE keeps the derived pepper apart from every other use of that key,
 * the way Signer does it for its own.
 *
 * Hence the pepper is not optional: a portal without crypto_key has no key to give, and pepper()
 * throws.
 */
class GeohashKey
{
	private const CONFIG_SECTION = 'crypto';
	private const CONFIG_KEY = 'crypto_key';
	private const PURPOSE = 'stafftrack.geohash';

	public static function hash(string $geohash): string
	{
		return hash_hmac('sha256', $geohash, self::pepper());
	}

	private static function pepper(): string
	{
		$cryptoKey = self::readCryptoKey();
		if ($cryptoKey === '')
		{
			throw new \RuntimeException('crypto_key is not configured');
		}

		return hash_hmac('sha256', self::PURPOSE, $cryptoKey);
	}

	private static function readCryptoKey(): string
	{
		$section = Configuration::getValue(self::CONFIG_SECTION);

		return (string)($section[self::CONFIG_KEY] ?? '');
	}
}
