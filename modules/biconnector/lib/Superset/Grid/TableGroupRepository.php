<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Superset\Grid;

use Bitrix\BIConnector\Access\AccessController;
use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Access\Service\TableGroupMembershipService;
use Bitrix\BIConnector\Access\Table\TableVisibilityService;
use Bitrix\BIConnector\Access\TableGroup\TableGroupTable;
use Bitrix\BIConnector\Configuration\Feature;
use Bitrix\BIConnector\ExternalSource\Internal\ExternalDatasetTable;
use Bitrix\BIConnector\ExternalSource\SupersetServiceIntegration;

/**
 * Rows of the table groups for the section and the composition behind each of them.
 *
 * The section repeats the reports one: the top level carries a row per group, and the tables of a
 * group are shown once the group is opened. Which groups are named here is decided by the same
 * answer that decides which tables are shown - the one of TableVisibilityService - so the section
 * cannot name a group whose tables it would then refuse to show.
 */
final class TableGroupRepository
{
	public const TYPE_GROUP = 'G';
	public const TYPE_TABLE = 'T';

	private TableVisibilityService $visibility;

	private AccessController $accessController;

	/**
	 * @var array<int, array<string, mixed>>|null Rows already built for this person.
	 *
	 * The section asks for them twice a hit - by the counter and by the page - and the answer is the
	 * same every time. The memo belongs to the object and dies with it: the write side of the groups
	 * builds no repository, so no memo outlives the change it would speak about.
	 */
	private ?array $groupRows = null;

	/**
	 * @param TableVisibilityService|null $visibility The answer of the rights axis; the one of the
	 *                                               current user when omitted.
	 * @param AccessController|null $accessController The controller to ask; the current one when omitted.
	 */
	public function __construct(
		?TableVisibilityService $visibility = null,
		?AccessController $accessController = null,
	)
	{
		$this->visibility = $visibility ?? new TableVisibilityService(null, $accessController);
		$this->accessController = $accessController ?? $this->visibility->getAccessController();
	}

	/**
	 * Returns a row per group the person is allowed to see.
	 *
	 * A group with no visible table keeps its row: an empty group is a place to put a table into,
	 * and hiding it would make the section lie about what the matrix says.
	 *
	 * The number of tables is not in the row: counting costs the walk of the whole catalog, and of
	 * those who ask for the rows only the grid of the top level shows that number. It is asked for
	 * apart, by fillTableCounts().
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function getGroupRows(): array
	{
		if ($this->groupRows !== null)
		{
			return $this->groupRows;
		}

		$this->groupRows = $this->loadGroupRows();

		return $this->groupRows;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function loadGroupRows(): array
	{
		// The groups are a part of the workspace of the analyst: the section of tables, the import and
		// the dialog of the filter that asks for these rows on behalf of any authorized user all answer
		// to the right of the workspace, in either state of the axis.
		if (!$this->getAccessController()->check(ActionDictionary::ACTION_BIC_EXTERNAL_DASHBOARD_CONFIG))
		{
			return [];
		}

		$filter = [];
		if (Feature::isTablePermissionsEnabled())
		{
			$allowedGroupIds = $this->getAllowedGroupIds();
			if (empty($allowedGroupIds))
			{
				return [];
			}

			$filter['@ID'] = $allowedGroupIds;
		}
		// Without the axis every group is shown, yet only to whoever may enter BI Builder at all.
		elseif (!$this->getAccessController()->check(ActionDictionary::ACTION_BIC_ACCESS))
		{
			return [];
		}

		$groups = TableGroupTable::getList([
			'select' => ['ID', 'CODE', 'NAME', 'TYPE', 'SORT', 'DATE_CREATE', 'CREATED_BY_ID', 'UPDATED_BY_ID'],
			'filter' => $filter,
			'order' => ['SORT' => 'ASC', 'NAME' => 'ASC'],
		])->fetchAll();

		$rows = [];
		foreach ($groups as $group)
		{
			$groupId = (int)$group['ID'];

			$rows[] = [
				'ID' => $groupId,
				'NAME' => (string)$group['NAME'],
				'CODE' => (string)$group['CODE'],
				'TYPE' => (string)$group['TYPE'],
				'SORT' => (int)$group['SORT'],
				'IS_SYSTEM' => $group['TYPE'] === TableGroupTable::GROUP_TYPE_SYSTEM,
				'ENTITY_TYPE' => self::TYPE_GROUP,
				'DATE_CREATE' => $group['DATE_CREATE'],
				// A group made before the columns existed names nobody, and the cell of such a row
				// stays empty.
				'CREATED_BY_ID' => (int)$group['CREATED_BY_ID'],
				'UPDATED_BY_ID' => (int)$group['UPDATED_BY_ID'],
			];
		}

		return $rows;
	}

	/**
	 * Tells each of these group rows how many tables it stands for.
	 *
	 * Only the rows the grid draws are handed here: the number is what the catalog is walked for, so
	 * whoever shows no number - the params of the section, the dialog of the filter - never pays for
	 * the walk.
	 *
	 * @param array<int, array<string, mixed>> $groupRows Rows of getGroupRows(), the drawn ones.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function fillTableCounts(array $groupRows): array
	{
		$groupIds = array_map(static fn(array $groupRow): int => (int)$groupRow['ID'], $groupRows);
		$existingCodes = $this->getExistingTableCodes(
			TableGroupMembershipService::getTableCodesByGroupIds($groupIds),
		);

		foreach ($groupRows as &$groupRow)
		{
			$groupRow['COUNT_TABLES'] = $this->countVisibleTables((int)$groupRow['ID'], $existingCodes);
		}
		unset($groupRow);

		return $groupRows;
	}

	/**
	 * Number of the tables of the group the person both may see and can be shown.
	 *
	 * A binding outlives the table it names: some are written for a code the catalog of this portal
	 * never publishes, and some stay behind a dataset that has been deleted. The grid inside a group
	 * draws a row for a code only while the catalog publishes it or a dataset row carries it, so the
	 * subtitle of the group counts by the very same two lists - otherwise it promises tables the
	 * opened group does not show.
	 *
	 * @param array<string, true> $existingCodes Result of getExistingTableCodes() for the drawn groups.
	 */
	private function countVisibleTables(int $groupId, array $existingCodes): int
	{
		$visibleCount = 0;
		foreach (TableGroupMembershipService::getTableCodesByGroupId($groupId) as $tableCode)
		{
			if (isset($existingCodes[$tableCode]) && $this->visibility->isTableAllowed($tableCode))
			{
				$visibleCount++;
			}
		}

		return $visibleCount;
	}

	/**
	 * Which of these codes exist behind the bindings, as the grid of the section knows them.
	 *
	 * Both lists are read once for all the drawn group rows and not once per row: the catalog is
	 * memoized for the hit by SupersetServiceIntegration, and the datasets are one query of a single
	 * column. The datasets are read directly and not through the catalog because the grid reads them
	 * that way: a dataset the catalog refused to publish still keeps its row. Only the codes of the
	 * drawn groups the catalog does not publish are looked up among the datasets, row by row.
	 *
	 * @param string[] $tableCodes Composition of the drawn groups.
	 *
	 * @return array<string, true>
	 */
	private function getExistingTableCodes(array $tableCodes): array
	{
		if ($tableCodes === [])
		{
			return [];
		}

		$catalogCodes = [];
		foreach (SupersetServiceIntegration::getTableList() as $tableCode)
		{
			$catalogCodes[(string)$tableCode] = true;
		}

		$existingCodes = [];
		$datasetCodes = [];
		foreach ($tableCodes as $tableCode)
		{
			if (isset($catalogCodes[$tableCode]))
			{
				$existingCodes[$tableCode] = true;
			}
			else
			{
				$datasetCodes[] = $tableCode;
			}
		}

		if ($datasetCodes === [])
		{
			return $existingCodes;
		}

		$datasetResult = ExternalDatasetTable::query()
			->setSelect(['NAME'])
			->whereIn('NAME', $datasetCodes)
			->exec()
		;
		while ($datasetRow = $datasetResult->fetch())
		{
			$existingCodes[(string)$datasetRow['NAME']] = true;
		}

		return $existingCodes;
	}

	/**
	 * Returns the catalog codes no group holds.
	 *
	 * Such a table is shown on the top level of the section next to the group rows. With the axis on
	 * only the person who manages the rights on tables sees it: nobody else can do anything about it,
	 * while that one has to see it to move it into a group. Without the axis everyone sees it, the way
	 * everyone sees every other table: hidden from all, it would be reachable by nobody.
	 *
	 * @return string[]
	 */
	public function getUngroupedTableCodes(): array
	{
		if (Feature::isTablePermissionsEnabled() && !$this->isGroupManager())
		{
			return [];
		}

		$ungroupedCodes = [];
		foreach (SupersetServiceIntegration::getTableList() as $tableCode)
		{
			$tableCode = (string)$tableCode;
			if (empty(TableGroupMembershipService::getGroupIdsByTableCode($tableCode)))
			{
				$ungroupedCodes[] = $tableCode;
			}
		}

		return $ungroupedCodes;
	}

	/**
	 * Returns the codes the grid is allowed to draw at the asked level of the section.
	 *
	 * The top level carries no table but the ungrouped ones, and an opened group carries its own
	 * composition narrowed down to what the person may see - the whole of it while the axis is off.
	 *
	 * @param int|null $groupId Null asks about the top level of the section.
	 *
	 * @return string[]
	 */
	public function getVisibleTableCodes(?int $groupId): array
	{
		if ($groupId === null || $groupId <= 0)
		{
			return $this->getUngroupedTableCodes();
		}

		return $this->getLevel([$groupId])->tableCodes ?? [];
	}

	/**
	 * Returns the level of the section the condition of the filter names.
	 *
	 * The top level carries the tables no group holds; the groups named by the condition carry the
	 * union of their compositions, narrowed down to what the person may see.
	 *
	 * @param int[] $groupIds Groups the condition names; an empty list is the top level.
	 */
	public function getLevel(array $groupIds): TableSectionLevel
	{
		if ($groupIds === [])
		{
			return new TableSectionLevel($this->getUngroupedTableCodes());
		}

		$levelGroupIds = array_values(array_unique(array_intersect($groupIds, $this->getLevelGroupIds())));
		if ($levelGroupIds === [])
		{
			return new TableSectionLevel([]);
		}

		// One read of the composition for the whole level instead of one per group of it: the answer of
		// the axis is asked about every code, and asking it group by group costs the catalog every time.
		$composition = TableGroupMembershipService::getTableCodesByGroupIds($levelGroupIds);
		$levelCodes = array_values(array_filter(
			$composition,
			fn(string $tableCode): bool => $this->visibility->isTableAllowed($tableCode),
		));

		// A query may name these groups instead of listing their codes only while the axis takes nothing
		// out of their composition: otherwise the id of a group would mean more tables than the person
		// sees. The codes are a subset of the composition, so equal sizes mean equal sets.
		return new TableSectionLevel(
			$levelCodes,
			count($levelCodes) === count($composition) ? $levelGroupIds : null,
		);
	}

	/**
	 * Returns the level a search over the top level of the section runs through.
	 *
	 * A search names no group, so it looks wherever the person may look: into every group they see
	 * and among the tables no group holds, which only the manager of the rights is offered.
	 *
	 * While no table stands outside the groups, the search may name the groups it looks into instead of
	 * listing their codes - under the same rule as an opened level of groups.
	 */
	public function getSearchLevel(): TableSectionLevel
	{
		if (!Feature::isTablePermissionsEnabled())
		{
			return new TableSectionLevel();
		}

		$allowedCodes = array_values(array_unique($this->visibility->getAllowedTableCodes() ?? []));
		$ungroupedCodes = $this->getUngroupedTableCodes();
		if ($ungroupedCodes !== [])
		{
			return new TableSectionLevel(array_values(array_unique(array_merge($allowedCodes, $ungroupedCodes))));
		}

		$allowedGroupIds = $this->getAllowedGroupIds();
		if ($allowedGroupIds === [])
		{
			return new TableSectionLevel($allowedCodes);
		}

		// The groups may be named only while the allowed codes are exactly their composition. The level of
		// these groups names them only while its codes are the whole composition; those codes then stand
		// for the allowed ones while they are a subset of them of the same size.
		$groupLevel = $this->getLevel($allowedGroupIds);
		$canNameGroups = $groupLevel->groupIds !== null
			&& count($groupLevel->tableCodes ?? []) === count($allowedCodes)
			&& array_diff($groupLevel->tableCodes ?? [], $allowedCodes) === []
		;

		return new TableSectionLevel($allowedCodes, $canNameGroups ? $groupLevel->groupIds : null);
	}

	public function isGroupManager(): bool
	{
		return $this->getAccessController()->check(ActionDictionary::ACTION_BIC_TABLE_GROUP_MANAGE);
	}

	/**
	 * Groups a level of the section may stand on: the allowed ones with the axis on, every existing
	 * one without it.
	 *
	 * @return int[]
	 */
	private function getLevelGroupIds(): array
	{
		if (Feature::isTablePermissionsEnabled())
		{
			return $this->getAllowedGroupIds();
		}

		return array_map('intval', array_column($this->getGroupRows(), 'ID'));
	}

	/**
	 * Groups of the axis the person is allowed to view.
	 *
	 * An action with no rule of its own answers null, and null is read here as "no group at all": the
	 * axis grants by naming its groups, so an answer that names none grants none.
	 *
	 * @return int[]
	 */
	private function getAllowedGroupIds(): array
	{
		$allowedGroupIds = $this->getAccessController()->getAllowedGroupValue(ActionDictionary::ACTION_BIC_TABLE_VIEW);

		return array_map('intval', $allowedGroupIds ?? []);
	}

	private function getAccessController(): AccessController
	{
		return $this->accessController;
	}
}
