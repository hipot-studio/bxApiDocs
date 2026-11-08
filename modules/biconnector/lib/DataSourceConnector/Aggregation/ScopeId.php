<?php

namespace Bitrix\BIConnector\DataSourceConnector\Aggregation;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Security\Random;

/**
 * Opaque identity of the physical source behind a table name (design §2.1).
 *
 * The plugin keeps it in the handle and compares it for equality only, so the raw source id never
 * leaves the portal. A changed source behind the same table name changes the value, and through it
 * the capabilities fingerprint.
 */
final class ScopeId
{
	private const SECRET_OPTION = 'aggregation_scope_secret';
	private const SECRET_LENGTH = 64;
	private const SYSTEM_SCOPE = 'system';

	private function __construct()
	{
	}

	public static function forSystemDataset(): string
	{
		return self::hash(self::SYSTEM_SCOPE);
	}

	public static function forExternalSource(int $sourceId): string
	{
		return self::hash((string)$sourceId);
	}

	private static function hash(string $value): string
	{
		return 'o:' . hash_hmac('sha256', $value, self::getSecret());
	}

	private static function getSecret(): string
	{
		$secret = (string)Option::get('biconnector', self::SECRET_OPTION, '');
		if ($secret !== '')
		{
			return $secret;
		}

		$secret = Random::getString(self::SECRET_LENGTH, true);
		Option::set('biconnector', self::SECRET_OPTION, $secret);

		return $secret;
	}
}
