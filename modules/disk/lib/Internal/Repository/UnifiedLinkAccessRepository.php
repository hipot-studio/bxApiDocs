<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Repository;

use Bitrix\Disk\Internal\
{Model\UnifiedLinkAccessTable};
use Bitrix\Disk\Internal\Access\UnifiedLink\UnifiedLinkAccessLevel;
use Bitrix\Main\Application;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\DB\SqlQueryException;
use Bitrix\Main\ORM\Data\AddResult;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;

class UnifiedLinkAccessRepository
{
	/**
	 * The level belongs to the object and not to whoever asks, while the access check asks it once per user.
	 * Both writers below drop the entry, so a level read after a change is still the stored one.
	 *
	 * The entry lives as long as the process does, and a change made by another process does not reach it.
	 * That costs nothing today: the table is written only by {@see self::set()} and {@see self::deleteByObjectId()},
	 * and every reader of the level is a web request, which ends long before a level is changed elsewhere.
	 * A long-lived reader - an agent or a consumer of a queue asking for the same object twice - would need
	 * a lifetime bound to something shorter than the process.
	 *
	 * @var array<int, UnifiedLinkAccessLevel|null> Levels already read in this process; null is a stored answer too.
	 */
	private static array $levelsByObjectId = [];

	/**
	 * @param int $objectId
	 * @return UnifiedLinkAccessLevel|null
	 * @throws ArgumentException
	 * @throws SystemException
	 * @throws ObjectPropertyException
	 */
	public static function getByObjectId(int $objectId): ?UnifiedLinkAccessLevel
	{
		if (array_key_exists($objectId, self::$levelsByObjectId))
		{
			return self::$levelsByObjectId[$objectId];
		}

		$result = UnifiedLinkAccessTable::query()
			->setSelect(['ACCESS_LEVEL'])
			->where('OBJECT_ID', $objectId)
			->setLimit(1)
			->fetch()
		;

		$level = isset($result['ACCESS_LEVEL']) && is_string($result['ACCESS_LEVEL'])
			? UnifiedLinkAccessLevel::tryFrom($result['ACCESS_LEVEL'])
			: null
		;

		self::$levelsByObjectId[$objectId] = $level;

		return $level;
	}

	/**
	 * @param array<int, int> $objectIds
	 * @return array<int, UnifiedLinkAccessLevel>
	 * @throws ArgumentException
	 * @throws SystemException
	 * @throws ObjectPropertyException
	 */
	public static function getByObjectIds(array $objectIds): array
	{
		$objectIds = array_values(array_unique(array_filter(array_map('intval', $objectIds))));

		if (empty($objectIds))
		{
			return [];
		}

		$accessLevels = [];

		$queryResult = UnifiedLinkAccessTable::query()
			->setSelect(['OBJECT_ID', 'ACCESS_LEVEL'])
			->whereIn('OBJECT_ID', $objectIds)
			->exec()
		;

		while ($row = $queryResult->fetch())
		{
			$accessLevel = UnifiedLinkAccessLevel::tryFrom((string)$row['ACCESS_LEVEL']);

			if ($accessLevel instanceof UnifiedLinkAccessLevel)
			{
				$accessLevels[(int)$row['OBJECT_ID']] = $accessLevel;
			}
		}

		return $accessLevels;
	}

	/**
	 * @param int $objectId
	 * @param UnifiedLinkAccessLevel $level
	 * @return AddResult
	 */
	public static function set(int $objectId, UnifiedLinkAccessLevel $level): AddResult
	{
		unset(self::$levelsByObjectId[$objectId]);

		return UnifiedLinkAccessTable::addMerge([
			'OBJECT_ID' => $objectId,
			'ACCESS_LEVEL' => $level->value,
		]);
	}

	/**
	 * @param int $objectId
	 * @return void
	 * @throws SqlQueryException
	 */
	public static function deleteByObjectId(int $objectId): void
	{
		unset(self::$levelsByObjectId[$objectId]);

		$connection = Application::getConnection();
		$tableName = UnifiedLinkAccessTable::getTableName();

		$connection->queryExecute("DELETE FROM {$tableName} WHERE OBJECT_ID = {$objectId}");
	}
}
