<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Sync\Position;

use Bitrix\Main\Config\Option;

/**
 * The key a position was signed with is named inside the position itself.
 *
 * Rotating a key that positions do not name would invalidate every live position of the portal at
 * once and send every client into a full state check. Named, a retired key keeps verifying the
 * positions it signed for as long as its version stays in the accepted list - the list is meant to
 * hold a version for the retention span of the journal, after which a position signed by it is
 * expired anyway.
 *
 * The version participates in the salt, so a key is never asked to verify what another key signed.
 */
final class PositionSignatureKey
{
	public const OPTION_CURRENT_VERSION = 'disk_sync_position_key_version';
	public const OPTION_RETIRED_VERSIONS = 'disk_sync_position_retired_key_versions';

	public const DEFAULT_VERSION = 'v1';

	private const SALT_PREFIX = 'diskSyncPosition.';

	/** No dot: the dot separates the version from the signed body of a position. */
	private const VERSION_PATTERN = '#^[a-zA-Z0-9_-]{1,16}$#D';

	private function __construct(
		private readonly string $version,
	)
	{
	}

	public static function current(): self
	{
		return new self(self::getCurrentVersion());
	}

	/**
	 * The key a position names, or null when that version is not accepted any more.
	 */
	public static function findAccepted(string $version): ?self
	{
		if (!in_array($version, self::getAcceptedVersions(), true))
		{
			return null;
		}

		return new self($version);
	}

	/**
	 * The current version first, then the retired ones still honoured.
	 *
	 * @return string[]
	 */
	public static function getAcceptedVersions(): array
	{
		$versions = [self::getCurrentVersion()];

		$retired = (string)Option::get('disk', self::OPTION_RETIRED_VERSIONS, '');
		foreach (explode(',', $retired) as $version)
		{
			$version = trim($version);
			if ($version !== '' && self::isWellFormed($version) && !in_array($version, $versions, true))
			{
				$versions[] = $version;
			}
		}

		return $versions;
	}

	public function getVersion(): string
	{
		return $this->version;
	}

	public function getSalt(): string
	{
		return self::SALT_PREFIX . $this->version;
	}

	private static function getCurrentVersion(): string
	{
		$version = trim((string)Option::get('disk', self::OPTION_CURRENT_VERSION, self::DEFAULT_VERSION));

		return self::isWellFormed($version) ? $version : self::DEFAULT_VERSION;
	}

	private static function isWellFormed(string $version): bool
	{
		return preg_match(self::VERSION_PATTERN, $version) === 1;
	}
}
