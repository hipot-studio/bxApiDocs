<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Internal\Dismiss\Storage;

use Bitrix\Main\Data\ConnectionPool;
use Bitrix\Main\DB\Connection;
use Bitrix\Mobile\Internal\Dismiss\DismissCode;

/**
 * The list of dismissed codes of a user, kept in a single \CUserOptions row: N dismissed elements
 * cost one row and one read after migration.
 */
class DismissStorage
{
	public const OPTION_CATEGORY = 'mobile';
	public const OPTION_NAME = 'dismissed';
	public const LEGACY_OPTION_CATEGORY = 'mobile_banner';
	public const MAX_CODES = 200;

	/**
	 * @param ConnectionPool $connectionPool the pool the connection routes its reads through
	 */
	public function __construct(
		private readonly Connection $connection,
		private readonly ConnectionPool $connectionPool,
	)
	{
	}

	/**
	 * @return string[]
	 */
	public function getCodes(int $userId): array
	{
		return $this->getState($userId)['codes'];
	}

	/**
	 * @return array{codes: string[], revision: int}
	 */
	public function getState(int $userId): array
	{
		$this->connectionPool->useMasterOnly(true);
		try
		{
			$read = $this->readStateAndLegacyPresence($userId);
		}
		finally
		{
			$this->connectionPool->useMasterOnly(false);
		}
		if ($read['state'] !== null && !$read['hasLegacy'])
		{
			return $read['state'];
		}

		return $this->withUserLock($userId, function () use ($userId): array {
			return $this->moveLegacyCodes($userId);
		});
	}

	/**
	 * @return string[]
	 */
	public function addCode(int $userId, string $code): array
	{
		return $this->addCodeState($userId, $code)['codes'];
	}

	/**
	 * @return array{codes: string[], revision: int}
	 */
	public function addCodeState(int $userId, string $code): array
	{
		return $this->withUserLock($userId, function () use ($userId, $code): array {
			$read = $this->readStateAndLegacyPresence($userId);
			$state = $read['state'] !== null && !$read['hasLegacy']
				? $read['state']
				: $this->moveLegacyCodes($userId);
			$codes = $state['codes'];
			if (in_array($code, $codes, true))
			{
				return $state;
			}

			$codes[] = $code;
			$codes = $this->capToLimit($codes);

			$state = ['codes' => $codes, 'revision' => $state['revision'] + 1];
			if (!\CUserOptions::SetOption(self::OPTION_CATEGORY, self::OPTION_NAME, $state, false, $userId))
			{
				throw new \RuntimeException('Could not save dismissed codes.');
			}

			return $state;
		});
	}

	private function withUserLock(int $userId, callable $action): array
	{
		$lockName = 'mobile:dismiss:' . $userId;
		if (!$this->connection->lock($lockName, 10))
		{
			throw new \RuntimeException('Could not lock dismissed codes for user.');
		}

		$this->connectionPool->useMasterOnly(true);
		try
		{
			return $action();
		}
		finally
		{
			try
			{
				$this->connection->unlock($lockName);
			}
			finally
			{
				$this->connectionPool->useMasterOnly(false);
			}
		}
	}

	/**
	 * Banner kept an option row per code in its own category. A late legacy row may appear after
	 * the list was created, so migration repeats whenever one is found.
	 *
	 * @return array{codes: string[], revision: int}
	 */
	private function moveLegacyCodes(int $userId): array
	{
		$existing = $this->readStateAndLegacyPresence($userId)['state'];
		$rows = \CUserOptions::GetList(['ID' => 'ASC'], [
			'USER_ID' => $userId,
			'CATEGORY' => self::LEGACY_OPTION_CATEGORY,
			'COMMON' => 'N',
		]);

		$codes = $existing['codes'] ?? [];
		$seen = array_fill_keys($codes, true);
		$legacyIds = [];
		while ($row = $rows->Fetch())
		{
			$legacyIds[] = (int)$row['ID'];
			if (!unserialize($row['VALUE'], ['allowed_classes' => false]))
			{
				continue;
			}

			$code = DismissCode::normalize((string)$row['NAME']);
			if ($code !== null && !isset($seen[$code]))
			{
				$seen[$code] = true;
				$codes[] = $code;
			}
		}
		if ($existing !== null && $legacyIds === [])
		{
			return $existing;
		}

		$codes = $this->capToLimit($codes);

		$state = ['codes' => $codes, 'revision' => ($existing['revision'] ?? 0) + 1];
		if (!\CUserOptions::SetOption(self::OPTION_CATEGORY, self::OPTION_NAME, $state, false, $userId))
		{
			throw new \RuntimeException('Could not save dismissed codes.');
		}

		if ($legacyIds !== [])
		{
			foreach (array_chunk($legacyIds, 500) as $ids)
			{
				$this->connection->queryExecute(
					'DELETE FROM b_user_option WHERE USER_ID = ' . $userId
					. " AND CATEGORY = '" . self::LEGACY_OPTION_CATEGORY . "' AND COMMON = 'N'"
					. ' AND ID IN (' . implode(',', $ids) . ')',
				);
			}

			// A name outside the code format invalidates both CUserOptions caches without touching a late code.
			$cacheKey = '~dismiss-cache-' . bin2hex(random_bytes(16));
			if (!\CUserOptions::DeleteOption(self::LEGACY_OPTION_CATEGORY, $cacheKey, false, $userId))
			{
				throw new \RuntimeException('Could not invalidate legacy dismissed codes cache.');
			}
		}

		return $state;
	}

	/**
	 * @param string[] $codes
	 * @return string[]
	 */
	private function capToLimit(array $codes): array
	{
		return count($codes) > self::MAX_CODES ? array_slice($codes, -self::MAX_CODES) : $codes;
	}

	/**
	 * The option belongs to the user and main.UserOption.saveOptions lets them write it themselves,
	 * so the list is held to the contract of the reader as well as of the writer.
	 *
	 * @param mixed[] $stored
	 * @return string[]
	 */
	private function sanitize(array $stored): array
	{
		$codes = [];
		// Keyed lookup keeps the pass linear; the keys are not the result, since a numeric code would become an int.
		$seen = [];
		foreach ($stored as $value)
		{
			$code = is_string($value) ? DismissCode::normalize($value) : null;
			if ($code !== null && !isset($seen[$code]))
			{
				$seen[$code] = true;
				$codes[] = $code;
			}
		}

		return $this->capToLimit($codes);
	}

	/**
	 * \CUserOptions caches the whole category for an hour and a concurrent request cannot
	 * invalidate that copy, so a read-modify-write has to bypass it.
	 *
	 * @return array{state: array{codes: string[], revision: int}|null, hasLegacy: bool}
	 */
	private function readStateAndLegacyPresence(int $userId): array
	{
		$rows = $this->connection->query(
			'SELECT UO.CATEGORY, UO.VALUE FROM b_user_option UO'
			. ' WHERE UO.USER_ID = ' . $userId . " AND UO.COMMON = 'N'"
			. " AND ((UO.CATEGORY = '" . self::OPTION_CATEGORY . "' AND UO.NAME = '" . self::OPTION_NAME . "')"
			. " OR UO.CATEGORY = '" . self::LEGACY_OPTION_CATEGORY . "')",
		);
		$state = null;
		$hasLegacy = false;
		while ($row = $rows->fetch())
		{
			if ($row['CATEGORY'] === self::LEGACY_OPTION_CATEGORY)
			{
				$hasLegacy = true;
				continue;
			}

			$state = $this->decode(unserialize($row['VALUE'], ['allowed_classes' => false]));
		}

		return ['state' => $state, 'hasLegacy' => $hasLegacy];
	}

	/**
	 * @return array{codes: string[], revision: int}|null
	 */
	private function decode(mixed $stored): ?array
	{
		if (!is_array($stored))
		{
			return null;
		}

		if (array_key_exists('codes', $stored) && array_key_exists('revision', $stored))
		{
			return [
				'codes' => $this->sanitize(is_array($stored['codes']) ? $stored['codes'] : []),
				'revision' => is_int($stored['revision']) && $stored['revision'] >= 0 ? $stored['revision'] : 0,
			];
		}

		return ['codes' => $this->sanitize($stored), 'revision' => 0];
	}
}
