<?php

namespace Bitrix\StaffTrack\Internal\Geo;

use Bitrix\Main\Config\Configuration;

class GeohashKey
{
	private const ITERATIONS = 600000;
	private const OUTPUT_HEX_LENGTH = 64;
	private const PEPPER_BYTES = 32;
	private const CONFIG_SECTION = 'stafftrack';
	private const PEPPER_KEY = 'geohash_pepper';

	/**
	 * @var array<string, string>
	 */
	private static array $hashCache = [];

	public static function hash(string $geohash): string
	{
		return self::$hashCache[$geohash] ??= hash_pbkdf2(
			'sha256',
			$geohash,
			self::pepper(),
			self::ITERATIONS,
			self::OUTPUT_HEX_LENGTH,
		);
	}

	public static function isAvailable(): bool
	{
		return self::readPepper() !== '';
	}

	public static function ensurePepper(): string
	{
		$pepper = self::readPepper();
		if ($pepper !== '')
		{
			return $pepper;
		}

		$pepper = bin2hex(random_bytes(self::PEPPER_BYTES));

		$config = Configuration::getInstance();
		$section = $config->get(self::CONFIG_SECTION) ?? [];
		$section[self::PEPPER_KEY] = $pepper;
		$config->add(self::CONFIG_SECTION, $section);
		$config->saveConfiguration();

		return $pepper;
	}

	private static function pepper(): string
	{
		$pepper = self::readPepper();
		if ($pepper === '')
		{
			throw new \RuntimeException('stafftrack geohash_pepper is not configured');
		}

		return $pepper;
	}

	private static function readPepper(): string
	{
		$section = Configuration::getValue(self::CONFIG_SECTION);

		return (string)($section[self::PEPPER_KEY] ?? '');
	}
}
