<?php

declare(strict_types=1);

namespace Bitrix\Rest\Update;

use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Update\Stepper;
use Bitrix\Rest\AppTable;
use Bitrix\Rest\PlacementLangTable;
use Bitrix\Rest\PlacementTable;

final class CleanOrphanedPlacements extends Stepper
{
	protected static $moduleId = 'rest';

	private const PLACEMENT_PHASE_BATCH_SIZE = 50;
	private const PLACEMENT_LANG_PHASE_BATCH_SIZE = 500;

	private const PHASE_KEY = 'phase';
	private const LAST_ID_KEY = 'lastId';
	private const PHASE_PLACEMENT = 'placement';
	private const PHASE_PLACEMENT_LANG = 'placement_lang';

	public function execute(array &$option): bool
	{
		$phase = $this->getPhase($option);
		$option[self::PHASE_KEY] = $phase;
		$lastId = (int)($option[self::LAST_ID_KEY] ?? 0);

		if ($phase === self::PHASE_PLACEMENT)
		{
			$placementIds = $this->getOrphanPlacementIds($lastId);
			if ($placementIds !== [])
			{
				PlacementTable::deleteByFilter(['@ID' => $placementIds]);
				$option[self::LAST_ID_KEY] = max($placementIds);

				return self::CONTINUE_EXECUTION;
			}

			$option[self::PHASE_KEY] = self::PHASE_PLACEMENT_LANG;
			$option[self::LAST_ID_KEY] = 0;
			$lastId = 0;
		}

		$langPlacementIds = $this->getOrphanLangPlacementIds($lastId);
		if ($langPlacementIds !== [])
		{
			PlacementLangTable::deleteByFilter(['@PLACEMENT_ID' => $langPlacementIds]);
			$option[self::LAST_ID_KEY] = max($langPlacementIds);

			return self::CONTINUE_EXECUTION;
		}

		PlacementTable::clearHandlerCache();

		return self::FINISH_EXECUTION;
	}

	private function getPhase(array $option): string
	{
		return ($option[self::PHASE_KEY] ?? '') === self::PHASE_PLACEMENT_LANG
			? self::PHASE_PLACEMENT_LANG
			: self::PHASE_PLACEMENT
		;
	}

	/**
	 * @return int[]
	 */
	private function getOrphanPlacementIds(int $lastId): array
	{
		$tableName = PlacementTable::getTableName();
		$appTableName = AppTable::getTableName();

		$filter = Query::filter()
			->where('APP_ID', '>', 0)
			->whereNotExists(new SqlExpression(
				'SELECT 1 FROM ?# WHERE ?#.?# = ?#.?#',
				$appTableName,
				$appTableName,
				'ID',
				$tableName,
				'APP_ID',
			))
		;

		if ($lastId > 0)
		{
			$filter->where('ID', '>', $lastId);
		}

		$rows = PlacementTable::query()
			->setCustomBaseTableAlias($tableName)
			->setSelect(['ID'])
			->where($filter)
			->setOrder(['ID' => 'ASC'])
			->setLimit(self::PLACEMENT_PHASE_BATCH_SIZE)
			->fetchAll()
		;

		return array_map(static fn(array $row): int => (int)$row['ID'], $rows);
	}

	/**
	 * @return int[]
	 */
	private function getOrphanLangPlacementIds(int $lastId): array
	{
		$tableName = PlacementLangTable::getTableName();
		$placementTableName = PlacementTable::getTableName();

		$filter = Query::filter()
			->whereNotExists(new SqlExpression(
				'SELECT 1 FROM ?# WHERE ?#.?# = ?#.?#',
				$placementTableName,
				$placementTableName,
				'ID',
				$tableName,
				'PLACEMENT_ID',
			))
		;

		if ($lastId > 0)
		{
			$filter->where('PLACEMENT_ID', '>', $lastId);
		}

		$rows = PlacementLangTable::query()
			->setCustomBaseTableAlias($tableName)
			->setDistinct()
			->setSelect(['PLACEMENT_ID'])
			->where($filter)
			->setOrder(['PLACEMENT_ID' => 'ASC'])
			->setLimit(self::PLACEMENT_LANG_PHASE_BATCH_SIZE)
			->fetchAll()
		;

		return array_map(static fn(array $row): int => (int)$row['PLACEMENT_ID'], $rows);
	}
}
