<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\Permission\Query;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Socialnetwork\UserToGroupTable;
use Bitrix\Socialnetwork\WorkgroupSiteTable;
use Bitrix\Socialnetwork\WorkgroupTable;

final class GroupViewBatchQuery
{
	public const MAX_GROUP_IDS = 100;

	/**
	 * @param int[] $groupIds
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function fetch(array $groupIds, int $userId, string $extranetSiteId): array
	{
		$groupIds = self::normalizeGroupIds($groupIds);
		if (count($groupIds) > self::MAX_GROUP_IDS)
		{
			throw new ArgumentException(
				'Group view query accepts at most ' . self::MAX_GROUP_IDS . ' identifiers per request.',
				'groupIds',
			);
		}

		if (empty($groupIds))
		{
			return [];
		}

		return self::mergeRows(
			$groupIds,
			self::indexRows(self::fetchFromDatabase($groupIds, $userId, $extranetSiteId)),
		);
	}

	/**
	 * @param int[] $groupIds
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function fetchFromDatabase(array $groupIds, int $userId, string $extranetSiteId): array
	{
		$query = self::createGroupQuery($groupIds, $extranetSiteId);
		$query->addSelect('MY_RELATION.ROLE', 'ROLE');
		$query->registerRuntimeField(
			(new Reference(
				name: 'MY_RELATION',
				referenceEntity: UserToGroupTable::class,
				referenceFilter: Join::on('this.ID', 'ref.GROUP_ID')->where('ref.USER_ID', $userId),
			))->configureJoinType(Join::TYPE_LEFT),
		);

		return $query->fetchAll();
	}

	/**
	 * @param int[] $groupIds
	 */
	private static function createGroupQuery(array $groupIds, string $extranetSiteId): Query
	{
		$query = WorkgroupTable::query()
			->setSelect(['ID', 'VISIBLE', 'TYPE', 'PROJECT'])
			->whereIn('ID', $groupIds)
		;

		if ($extranetSiteId !== '')
		{
			$query->registerRuntimeField(
				(new Reference(
					name: 'EXTRANET_SITE',
					referenceEntity: WorkgroupSiteTable::class,
					referenceFilter: Join::on('this.ID', 'ref.GROUP_ID')->where('ref.SITE_ID', $extranetSiteId),
				))->configureJoinType(Join::TYPE_LEFT),
			);

			$query->addSelect('EXTRANET_SITE.SITE_ID', 'EXTRANET_SITE_ID');
		}

		return $query;
	}

	/**
	 * Collapses duplicate relation rows because any role implies membership.
	 *
	 * @param array<int, array<string, mixed>> $rows
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function indexRows(array $rows): array
	{
		$indexedRows = [];

		foreach ($rows as $row)
		{
			$groupId = (int)$row['ID'];
			$knownRole = $indexedRows[$groupId]['ROLE'] ?? null;

			$indexedRows[$groupId] = $row;
			$indexedRows[$groupId]['ROLE'] = $row['ROLE'] ?? $knownRole;
		}

		return $indexedRows;
	}

	/**
	 * @param int[] $groupIds
	 * @param array<int, array<string, mixed>> $indexedRows
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function mergeRows(array $groupIds, array $indexedRows): array
	{
		$rows = [];

		foreach ($groupIds as $groupId)
		{
			if (isset($indexedRows[$groupId]))
			{
				$rows[] = $indexedRows[$groupId];
			}
		}

		return $rows;
	}

	/**
	 * @param int[] $groupIds
	 *
	 * @return int[]
	 */
	private static function normalizeGroupIds(array $groupIds): array
	{
		$normalizedGroupIds = [];

		foreach ($groupIds as $groupId)
		{
			$groupId = (int)$groupId;
			if ($groupId > 0)
			{
				$normalizedGroupIds[$groupId] = $groupId;
			}
		}

		sort($normalizedGroupIds, SORT_NUMERIC);

		return array_values($normalizedGroupIds);
	}
}
