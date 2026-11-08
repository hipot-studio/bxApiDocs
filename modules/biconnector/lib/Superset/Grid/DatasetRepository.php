<?php

namespace Bitrix\BIConnector\Superset\Grid;

use Bitrix\BIConnector\Access\Table\TableVisibilityService;
use Bitrix\BIConnector\Access\TableGroup\TableGroupBindingTable;
use Bitrix\BIConnector\ExternalSource\Internal\ExternalDatasetTable;
use Bitrix\BIConnector\ExternalSource\Internal\ExternalSourceDatasetRelationTable;
use Bitrix\BIConnector\ExternalSource\Internal\ExternalSourceTable;
use Bitrix\BIConnector\DataSource\SystemDatasetProvider;
use Bitrix\Main\ORM\Fields\Relations;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\ORM\Query\Query;

class DatasetRepository
{
	/** Field the grid filter keeps the level of the section in; the reports section names it the same way. */
	public const FILTER_FIELD_GROUP = 'GROUPS.ID';

	private array $systemDatasetsCache = [];
	private array $userCountCache = [];
	private TableVisibilityService $visibility;
	private TableGroupRepository $groupRepository;

	/** @var string[]|null Codes allowed at the level being drawn; null while the axis is off. */
	private ?array $levelTableCodes = null;

	/** @var int[]|null Groups a query may name instead of those codes; null while it may not. */
	private ?array $levelGroupIds = null;

	private bool $isLevelSelected = false;

	private bool $isGroupLevel = false;

	/** @var int[] Groups the condition of the filter names for the level chosen last. */
	private array $selectedGroupIds = [];

	/** @var array Conditions of the filter beside the level; the group rows of the top level meet them too. */
	private array $levelConditions = [];

	/**
	 * @param TableVisibilityService|null $visibility The answer of the rights axis; the one of the
	 *                                               current user when omitted.
	 * @param TableGroupRepository|null $groupRepository Rows of the groups and the composition behind
	 *                                                  each of them; built for the same person when omitted.
	 */
	public function __construct(
		?TableVisibilityService $visibility = null,
		?TableGroupRepository $groupRepository = null,
	)
	{
		$this->visibility = $visibility ?? new TableVisibilityService();
		$this->groupRepository = $groupRepository ?? new TableGroupRepository($this->visibility);
	}

	public function getSystemDatasets(array $ormFilter = []): array
	{
		if ($this->getRestrictionCodes() === [])
		{
			// Not a single code is allowed here, so no row of the catalog can come back. The catalog is
			// built by an event sent to every module, and building it to throw it away costs the hit the
			// whole walk: the top level of the section pays it on every page a non-manager opens.
			return [];
		}

		$cacheKey = $this->cacheKey($ormFilter);
		if (array_key_exists($cacheKey, $this->systemDatasetsCache))
		{
			return $this->systemDatasetsCache[$cacheKey];
		}

		try
		{
			$rows = (new SystemDatasetProvider($this->levelVisibility()))->getList($ormFilter);
			$this->systemDatasetsCache[$cacheKey] = $this->restrictRowsToLevel($rows);

			return $this->systemDatasetsCache[$cacheKey];
		}
		catch (\Throwable)
		{
			return [];
		}
	}

	/**
	 * Chooses the level of the section the next reads speak about.
	 *
	 * The top level carries the group rows and the tables no group holds; the groups named by the
	 * filter carry the union of their compositions. Until a level is chosen the repository answers
	 * about everything the person may see, which is what the callers outside the section ask for.
	 *
	 * @param int[] $groupIds Groups the condition of the filter names; an empty list is the top level.
	 */
	public function selectLevel(array $groupIds): void
	{
		$this->applyLevel($this->groupRepository->getLevel($groupIds), $groupIds, []);
	}

	/**
	 * Chooses the top level searched by the filter: the tables come from everywhere the person may
	 * look, groups included, and a group row stays only while the group itself meets the filter.
	 *
	 * The reports section searches its top level the same way.
	 *
	 * @param array $conditions Conditions of the filter, the level taken out; at least one of them is set.
	 */
	private function selectSearchLevel(array $conditions): void
	{
		$this->applyLevel($this->groupRepository->getSearchLevel(), [], $conditions);
	}

	/**
	 * @param int[] $groupIds
	 */
	private function applyLevel(TableSectionLevel $level, array $groupIds, array $conditions): void
	{
		$this->levelTableCodes = $level->tableCodes;
		$this->levelGroupIds = $level->groupIds;
		$this->isLevelSelected = true;
		$this->isGroupLevel = $groupIds !== [];
		$this->selectedGroupIds = $groupIds;
		$this->levelConditions = $conditions;
	}

	/**
	 * Tells whether the level of the section stands on a group right now.
	 */
	public function isGroupLevelSelected(): bool
	{
		return $this->isGroupLevel;
	}

	/**
	 * Groups the level of the section stands on right now; an empty list is the top level.
	 *
	 * @return int[]
	 */
	public function getSelectedGroupIds(): array
	{
		return $this->selectedGroupIds;
	}

	public function getUserDatasets(array $select, array $filter, array $order, int $limit, int $offset): array
	{
		if (!in_array('EXTERNAL_ID', $select, true))
		{
			$select[] = 'EXTERNAL_ID';
		}

		$query = ExternalDatasetTable::query()
			->setSelect($select)
			->setFilter($filter)
			->registerRuntimeField(
				(new Relations\Reference(
					'SOURCE_RELATION',
					ExternalSourceDatasetRelationTable::class,
					Join::on('this.ID', 'ref.DATASET_ID')
				))
			)
			->registerRuntimeField(
				(new Relations\Reference(
					'SOURCE',
					ExternalSourceTable::class,
					Join::on('this.SOURCE_RELATION.SOURCE_ID', 'ref.ID')
				))
			)
			->setLimit($limit)
			->setOffset($offset)
			->setOrder($order)
		;

		if (!$this->restrictQueryByVisibility($query))
		{
			return [];
		}

		$result = $query->exec();

		$rows = [];
		$datasetIds = [];
		while ($row = $result->fetch())
		{
			$row['SOURCE'] = null;
			$row['IS_SYSTEM'] = false;
			$row['ENTITY_TYPE'] = TableGroupRepository::TYPE_TABLE;
			$datasetIds[] = $row['ID'];
			$rows[] = $row;
		}

		if (!empty($datasetIds))
		{
			$sourceMap = $this->loadSourcesForDatasets($datasetIds);
			foreach ($rows as &$row)
			{
				$row['SOURCE'] = $sourceMap[$row['ID']] ?? null;
			}
			unset($row);
		}

		return $rows;
	}

	public function getUserDatasetsCount(array $filter): int
	{
		$cacheKey = $this->cacheKey($filter);
		if (array_key_exists($cacheKey, $this->userCountCache))
		{
			return $this->userCountCache[$cacheKey];
		}

		$query = ExternalDatasetTable::query()
			->setSelect(['ID'])
			->setFilter($filter)
			->registerRuntimeField(
				(new Relations\Reference(
					'SOURCE_RELATION',
					ExternalSourceDatasetRelationTable::class,
					Join::on('this.ID', 'ref.DATASET_ID')
				))
			)
			->registerRuntimeField(
				(new Relations\Reference(
					'SOURCE',
					ExternalSourceTable::class,
					Join::on('this.SOURCE_RELATION.SOURCE_ID', 'ref.ID')
				))
			)
		;

		$this->userCountCache[$cacheKey] = $this->restrictQueryByVisibility($query)
			? $query->queryCountTotal()
			: 0
		;

		return $this->userCountCache[$cacheKey];
	}

	/**
	 * @param array $ormFilter Filter of the grid; the condition by group in it names the level.
	 */
	public function getTotalCount(array $ormFilter): int
	{
		$ormFilter = $this->selectLevelFromFilter($ormFilter);

		return count($this->getGroupRows())
			+ $this->getUserDatasetsCount($ormFilter)
			+ count($this->getSystemDatasets($ormFilter))
		;
	}

	/**
	 * Builds a page of rows: user rows first, system rows fill the tail.
	 *
	 * The group rows open the page of the top level: a group is a place the tables live in, so the
	 * section names the places first and only then the tables that belong to no place at all.
	 *
	 * @param array $ormParams Grid ORM params (select, filter, order).
	 * @param int $pageOffset Current page offset.
	 * @param int $pageLimit Current page size.
	 * @return array Rows for the current page.
	 */
	public function getPageRows(array $ormParams, int $pageOffset, int $pageLimit): array
	{
		$ormParams['filter'] = $this->selectLevelFromFilter($ormParams['filter'] ?? []);

		$groupRows = $this->getGroupRows();
		$groupCount = count($groupRows);

		$pageRows = [];
		if ($pageOffset < $groupCount)
		{
			// The number of tables is asked for here and nowhere else: this is the only place a group
			// row is drawn by, and the catalog is walked to answer it.
			$pageRows = $this->groupRepository->fillTableCounts(
				array_slice($groupRows, $pageOffset, $pageLimit),
			);
		}

		$tableOffset = max(0, $pageOffset - $groupCount);
		$tableLimit = $pageLimit - count($pageRows);
		if ($tableLimit <= 0)
		{
			return $pageRows;
		}

		$ormCount = $this->getUserDatasetsCount($ormParams['filter'] ?? []);
		$systemRows = $this->getSystemDatasets($ormParams['filter'] ?? []);

		if ($tableOffset >= $ormCount)
		{
			$systemOffset = $tableOffset - $ormCount;

			return array_merge($pageRows, array_slice($systemRows, $systemOffset, $tableLimit));
		}

		$userLimit = min($tableLimit, $ormCount - $tableOffset);
		$userRows = $this->getUserDatasets(
			$ormParams['select'] ?? [],
			$ormParams['filter'] ?? [],
			$ormParams['order'] ?? [],
			$userLimit,
			$tableOffset,
		);

		$freeSlots = $tableLimit - count($userRows);
		if ($freeSlots > 0)
		{
			$userRows = array_merge($userRows, array_slice($systemRows, 0, $freeSlots));
		}

		return array_merge($pageRows, $userRows);
	}

	/**
	 * Rows of the groups shown above the tables; a level picked in the filter shows tables alone.
	 *
	 * A search keeps the groups whose name it matches. Their subtitle still counts every table of the
	 * group, the way the reports section counts the reports of its groups.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function getGroupRows(): array
	{
		if ($this->isGroupLevel)
		{
			return [];
		}

		if (!self::hasConditions($this->levelConditions))
		{
			return $this->groupRepository->getGroupRows();
		}

		$nameFilter = self::getGroupNameFilter($this->levelConditions);
		if ($nameFilter === null)
		{
			return [];
		}

		$groupRows = $this->groupRepository->getGroupRows();
		if ($nameFilter === '')
		{
			return $groupRows;
		}

		return array_values(array_filter(
			$groupRows,
			static fn(array $groupRow): bool => mb_stripos((string)$groupRow['NAME'], $nameFilter) !== false,
		));
	}

	/**
	 * The search text a group row is held against.
	 *
	 * A group is matched by its name the way a system table is: SystemDatasetProvider looks for the
	 * text in the name, case aside. Every other field of the filter - the type and the source of a
	 * table, its author, its dates, its id - speaks of tables, so a condition on it leaves no group.
	 *
	 * @param array $conditions Conditions of the filter, the level taken out.
	 *
	 * @return string|null Null when a condition no group can meet is set.
	 */
	private static function getGroupNameFilter(array $conditions): ?string
	{
		$nameFilter = '';
		foreach ($conditions as $field => $value)
		{
			if (self::isBlankCondition($value))
			{
				continue;
			}

			if ($field !== 'NAME' || !is_string($value))
			{
				return null;
			}

			$nameFilter = trim($value, "% \t\n\r\0\x0B");
		}

		return $nameFilter;
	}

	/**
	 * Tells whether the filter, the level taken out, asks for anything at all.
	 *
	 * The grid hands over neither its service keys (FIND, PRESET_ID and the like) nor the fields left
	 * blank, and the quick search reaches here as a condition on NAME. A blank value is still skipped
	 * here, so that a filter built by hand cannot turn the top level into a search for nothing.
	 */
	private static function hasConditions(array $filter): bool
	{
		foreach ($filter as $value)
		{
			if (!self::isBlankCondition($value))
			{
				return true;
			}
		}

		return false;
	}

	private static function isBlankCondition(mixed $value): bool
	{
		return $value === '' || $value === [];
	}

	/**
	 * Takes the level of the section out of the filter and leaves the rest of it to the query.
	 *
	 * The condition by group is the level itself and never travels into the query: the entity of the
	 * tables carries no relation to the groups, and the codes of the level narrow the query instead.
	 * Any other condition on the top level makes it a search: see selectSearchLevel().
	 *
	 * @return array The filter the reads of this level are run with.
	 */
	private function selectLevelFromFilter(array $filter): array
	{
		$groupIds = [];
		foreach ((array)($filter[self::FILTER_FIELD_GROUP] ?? []) as $value)
		{
			$groupId = (int)$value;
			if ($groupId > 0)
			{
				$groupIds[] = $groupId;
			}
		}

		unset($filter[self::FILTER_FIELD_GROUP]);
		if ($groupIds === [] && self::hasConditions($filter))
		{
			$this->selectSearchLevel($filter);
		}
		else
		{
			$this->selectLevel($groupIds);
		}

		return $filter;
	}

	/**
	 * Narrows a query of the section down to the tables the person is allowed to see.
	 *
	 * The condition belongs to the query itself rather than to the rows it returned: a page filtered
	 * after the fact leaves the counter and the page boundaries speaking about the whole set, and the
	 * numbers alone then tell the person what is hidden from them.
	 *
	 * @return bool False when nothing at all is visible: no query is worth running then.
	 */
	private function restrictQueryByVisibility(Query $query): bool
	{
		$allowedTableCodes = $this->getRestrictionCodes();
		if ($allowedTableCodes === null)
		{
			return true;
		}

		if ($allowedTableCodes === [])
		{
			return false;
		}

		if ($this->levelGroupIds !== null)
		{
			// The codes of the level are the composition of these groups, so the query names the groups
			// and lets the binding table answer with the codes. A list of literals grows with the whole
			// allowed catalog, and choosing every group of the section is an ordinary thing to do.
			$query->whereIn(
				'NAME',
				TableGroupBindingTable::query()
					->setSelect(['TABLE_CODE'])
					->whereIn('GROUP_ID', $this->levelGroupIds)
			);

			return true;
		}

		$query->whereIn('NAME', $allowedTableCodes);

		return true;
	}

	/**
	 * The codes the reads are narrowed down to: those of the chosen level, or the whole answer of
	 * the axis while no level has been chosen.
	 *
	 * @return string[]|null Null leaves the reads as they were before the axis.
	 */
	private function getRestrictionCodes(): ?array
	{
		return $this->isLevelSelected
			? $this->levelTableCodes
			: $this->visibility->getAllowedTableCodes()
		;
	}

	/**
	 * Keeps the rows built outside the query within the chosen level and marks them as table rows.
	 *
	 * @param array<int, array<string, mixed>> $rows
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function restrictRowsToLevel(array $rows): array
	{
		$restrictionCodes = $this->getRestrictionCodes();
		$allowedCodes = $restrictionCodes === null ? null : array_flip($restrictionCodes);

		$restricted = [];
		foreach ($rows as $row)
		{
			if ($allowedCodes !== null && !isset($allowedCodes[$row['NAME'] ?? '']))
			{
				continue;
			}

			$row['ENTITY_TYPE'] = TableGroupRepository::TYPE_TABLE;
			$restricted[] = $row;
		}

		return $restricted;
	}

	/**
	 * The answer the catalog is read through at the chosen level.
	 */
	private function levelVisibility(): TableVisibilityService
	{
		return $this->isLevelSelected
			? TableVisibilityService::forAllowedCodes($this->levelTableCodes)
			: $this->visibility
		;
	}

	private function cacheKey(array $ormFilter): string
	{
		// The level carries the codes of the whole catalog, and the key is built several times a hit:
		// the digest keeps the key short instead of copying the list into it.
		return md5(serialize([$ormFilter, $this->isLevelSelected, $this->levelTableCodes]));
	}

	private function loadSourcesForDatasets(array $datasetIds): array
	{
		$result = ExternalSourceDatasetRelationTable::query()
			->addSelect('DATASET_ID')
			->addSelect('SOURCE.ID', 'SRC_ID')
			->addSelect('SOURCE.TYPE', 'SRC_TYPE')
			->addSelect('SOURCE.TITLE', 'SRC_TITLE')
			->whereIn('DATASET_ID', $datasetIds)
			->exec()
		;

		$map = [];
		while ($row = $result->fetch())
		{
			$datasetId = $row['DATASET_ID'];
			if (isset($map[$datasetId]))
			{
				continue;
			}

			$map[$datasetId] = [
				'ID' => $row['SRC_ID'],
				'TYPE' => $row['SRC_TYPE'],
				'TITLE' => $row['SRC_TITLE'],
			];
		}

		return $map;
	}
}
