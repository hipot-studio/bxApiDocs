<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Grid\Replication\Column\DataProvider;

use Bitrix\Main\Grid\Column\Column;
use Bitrix\Main\Grid\Column\DataProvider;
use Bitrix\Main\Grid\Column\Type;
use Bitrix\Main\Localization\Loc;
use Bitrix\Tasks\V2\Internal\Service\Grid\Replication\Column\ColumnSortMap;

Loc::loadMessages($_SERVER['DOCUMENT_ROOT'] . BX_ROOT . '/modules/tasks/lib/grid/task/grid.php');

/**
 * Column schema of the "Regular tasks" section grid (COL-01, P1.T1).
 *
 * The column set is dynamic: fixed attribute columns (NAME, ASSIGNEE, CONDITION, DEADLINE)
 * + one column per period segment (`PERIOD_<key>`, where `<key>` is the bucket key from
 * {@see \Bitrix\Tasks\V2\Internal\Service\Replication\Schedule\PeriodColumns}). The array
 * order equals the grid order.
 *
 * All columns are {@see Type::HTML}: the frontend Vue overlays are mounted onto the cells.
 * The provider describes only the schema; the data-to-cell mapping is in
 * {@see \Bitrix\Tasks\V2\Internal\Service\Grid\Replication\Row\ReplicationRows}.
 * NAME is pinned left natively ({@see Column::setSticked()}); no column is pinned right.
 *
 * Period columns are regenerated for the current period, so their specific IDs in a
 * personal view are not meaningful. Their items are hidden in the built-in column settings;
 * the dedicated "Schedule graph" switch controls the graph, and the grid replaces stale
 * period IDs while normalizing the persisted view
 * ({@see \Bitrix\Tasks\V2\Internal\Service\Grid\Replication\ReplicationGrid::normalizePersistedView()}).
 * The section columns are visible by default. Additional task attributes are available only
 * through the grid settings and do not change an existing personal view.
 *
 * The four list columns are sortable: each carries the section sorting it is paired with in
 * {@see ColumnSortMap}, so a header click and the gear menu drive one and the same state.
 * Period segments are aggregates over the window and stay unsortable.
 */
class ReplicationProvider extends DataProvider
{
	public const NAME_COLUMN = 'NAME';
	public const ASSIGNEE_COLUMN = 'ASSIGNEE';
	public const CONDITION_COLUMN = 'CONDITION';
	public const DEADLINE_COLUMN = 'DEADLINE';
	public const ORIGINATOR_NAME_COLUMN = 'ORIGINATOR_NAME';
	public const GROUP_NAME_COLUMN = 'GROUP_NAME';
	public const ID_COLUMN = 'ID';
	public const AUDITOR_COLUMN = 'U';
	public const ACCOMPLICE_COLUMN = 'A';

	/**
	 * @deprecated The single schedule column is replaced by dynamic period columns (COL-01).
	 * Kept only so that {@see \Bitrix\Tasks\V2\Internal\Service\Grid\Replication\Row\FrameRows}, removed
	 * from the flow, remain loadable for rollback (P3.T2). Do not use in new code.
	 */
	public const SCHEDULE_COLUMN = 'SCHEDULE';

	public const PERIOD_COLUMN_PREFIX = 'PERIOD_';

	/** Direction of the first click on a header; the next click flips it. */
	private const FIRST_SORT_ORDER = 'asc';

	/**
	 * @param array<int, array{key: string, label: string, sub: string, isNow: bool}> $segments
	 */
	public function __construct(
		private readonly array $segments = [],
	)
	{
		parent::__construct();
	}

	/**
	 * Wire id of a period-segment column by bucket key (`PERIOD_<key>`, `<key>` = `Y-m-d`).
	 */
	public static function periodColumnId(string $bucketKey): string
	{
		return self::PERIOD_COLUMN_PREFIX . $bucketKey;
	}

	/**
	 * @return Column[]
	 */
	public function prepareColumns(): array
	{
		$columns = [
			$this
				->createSortableColumn(self::NAME_COLUMN)
				->setName(Loc::getMessage('TASKS_V2_REPLICATION_GRID_COLUMN_NAME'))
				->setType(Type::HTML)
				->setDefault(true)
				->setSticked(true)
				->setWidth(380)
			,
			$this
				->createSortableColumn(self::ASSIGNEE_COLUMN)
				->setName(Loc::getMessage('TASKS_V2_REPLICATION_GRID_COLUMN_ASSIGNEE'))
				->setType(Type::HTML)
				->setDefault(true)
				->setWidth(220)
			,
			$this
				->createSortableColumn(self::CONDITION_COLUMN)
				->setName(Loc::getMessage('TASKS_V2_REPLICATION_GRID_COLUMN_CONDITION'))
				->setType(Type::HTML)
				->setDefault(true)
				->setWidth(280)
			,
			$this
				->createSortableColumn(self::DEADLINE_COLUMN)
				->setName(Loc::getMessage('TASKS_V2_REPLICATION_GRID_COLUMN_DEADLINE'))
				->setType(Type::HTML)
				->setDefault(true)
			,
			$this
				->createColumn(self::ORIGINATOR_NAME_COLUMN)
				->setName(Loc::getMessage('TASKS_GRID_TASK_GRID_HEADER_ORIGINATOR_NAME'))
				->setType(Type::HTML)
				->setDefault(false)
			,
			$this
				->createColumn(self::GROUP_NAME_COLUMN)
				->setName(Loc::getMessage('TASKS_GRID_TASK_GRID_HEADER_GROUP_NAME'))
				->setType(Type::HTML)
				->setDefault(false)
				->setWidth(220)
			,
			$this
				->createColumn(self::ID_COLUMN)
				->setName(Loc::getMessage('TASKS_GRID_TASK_GRID_HEADER_ID'))
				->setType(Type::INT)
				->setDefault(false)
			,
			$this
				->createColumn(self::AUDITOR_COLUMN)
				->setName(Loc::getMessage('TASKS_GRID_TASK_GRID_HEADER_AUDITOR_NAME'))
				->setType(Type::HTML)
				->setDefault(false)
			,
			$this
				->createColumn(self::ACCOMPLICE_COLUMN)
				->setName(Loc::getMessage('TASKS_GRID_TASK_GRID_HEADER_ACCOMPLICE_NAME'))
				->setType(Type::HTML)
				->setDefault(false)
			,
		];

		foreach ($this->segments as $segment)
		{
			$columns[] = $this
				->createColumn(self::periodColumnId((string)$segment['key']))
				->setName((string)($segment['label'] ?? ''))
				->setType(Type::HTML)
				->setDefault(true)
				->setResizeable(false)
				// a click on the cell opens the agenda of its segment, so it must not also
				// toggle the row checkbox as a click on an ordinary cell does
				->setPreventDefault(false)
			;
		}

		return $columns;
	}

	/**
	 * Column whose header sorts the list by the section sorting paired with it in
	 * {@see ColumnSortMap}: the native grid puts that sorting into `by` and the section reads it
	 * back as the chosen setting.
	 */
	private function createSortableColumn(string $columnId): Column
	{
		return $this
			->createColumn($columnId)
			->setSort(ColumnSortMap::sortByColumnId($columnId))
			->setFirstOrder(self::FIRST_SORT_ORDER)
		;
	}
}
