<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Grid\Replication;

use Bitrix\Main\Grid\Column\Columns;
use Bitrix\Main\Grid\Grid;
use Bitrix\Main\Grid\Pagination\LazyLoadTotalCount;
use Bitrix\Main\Grid\Panel\Panel;
use Bitrix\Main\Grid\Row\Rows;
use Bitrix\Main\Grid\Settings;
use Bitrix\Main\UI\PageNavigation;
use Bitrix\Tasks\V2\Internal\Service\Grid\Replication\Column\DataProvider\ReplicationProvider;
use Bitrix\Tasks\V2\Internal\Service\Grid\Replication\Panel\ReplicationPanel;
use Bitrix\Tasks\V2\Internal\Service\Grid\Replication\Row\Action\ReplicationRowActions;
use Bitrix\Tasks\V2\Internal\Service\Grid\Replication\Row\ReplicationRows;

/**
 * Server grid of the recurrence rule list in the "Regular tasks" section.
 *
 * HTML is loaded via ajax (API-S1/API-S2), Vue overlays are mounted onto the cells
 * (TPL-S1). The column set is dynamic: attribute columns and, while the schedule
 * graph is on ({@see isScheduleEnabled()}), one column per period segment (COL-01). Segments come
 * from {@see \Bitrix\Tasks\V2\Internal\Service\Replication\Schedule\PeriodColumns};
 * columns and rows are built from the same split, so the cell `data-bucket-key`
 * matches the period-column ids.
 *
 * Having a panel ({@see ReplicationPanel}) enables row checkboxes and the action panel
 * via `ComponentParams::get()`; group actions are handled on the frontend via
 * `Replication.Batch.*` (P4.T3).
 */
class ReplicationGrid extends Grid
{
	/**
	 * The panel counter shows "Show count" instead of a number: paging is built on the
	 * limit + 1 lookahead, so the record count of the navigation is "the page plus one more",
	 * not the total. The exact total is a separate COUNT requested on demand - the calculator
	 * is set by the controller ({@see LazyLoadTotalCount::setTotalCountCalculator()}).
	 */
	use LazyLoadTotalCount;

	private ?PageNavigation $pageNavigation = null;
	private ?bool $resolvedScheduleEnabled = null;

	/**
	 * @param array<int, array{key: string, label: string, sub: string, isNow: bool}> $segments
	 * @param bool|null $scheduleEnabled state the section switch asks for; null keeps the saved one
	 */
	public function __construct(
		Settings $settings,
		private readonly array $segments = [],
		private readonly ?bool $scheduleEnabled = null,
	)
	{
		parent::__construct($settings);
	}

	/**
	 * Whether the schedule graph is shown - the state of the "Schedule graph" switch of the
	 * control bar.
	 *
	 * The state lives in the personal grid view: it is enabled exactly while the view holds at
	 * least one period column. The period column set is dynamic and regenerated on every period
	 * change, so a saved `PERIOD_*` id is stale as an id - only its presence carries meaning.
	 * The switch flips that same flag through {@see normalizePersistedView()} instead of owning a
	 * second source of truth, so a request carrying no explicit state keeps the saved one.
	 *
	 * The graph is off by default: a first visit has an empty saved view and hence no period
	 * column in it.
	 */
	public function isScheduleEnabled(): bool
	{
		$this->resolvedScheduleEnabled ??= $this->scheduleEnabled
			?? $this->hasPeriodColumn($this->getOptions()->GetVisibleColumns());

		return $this->resolvedScheduleEnabled;
	}

	protected function createColumns(): Columns
	{
		return new Columns(new ReplicationProvider($this->getVisibleSegments()));
	}

	/**
	 * Period segments of the current window, or none while the graph is off: then the section is
	 * a plain list of recurrence rules and declares no segment column at all - neither in the
	 * grid nor in its built-in column settings window.
	 *
	 * @return array<int, array{key: string, label: string, sub: string, isNow: bool}>
	 */
	private function getVisibleSegments(): array
	{
		return $this->isScheduleEnabled() ? $this->segments : [];
	}

	/**
	 * Returns valid saved attribute columns plus every current period column while the graph is on.
	 *
	 * Unknown and removed column ids are ignored. Saved period ids are migration flags rather than
	 * concrete ids: any old `PERIOD_*` keeps the graph enabled and is replaced with all current
	 * period columns. An empty view or a view containing only removed ids falls back to the canonical
	 * column set for the resolved graph state. `NAME` is always present: it is the transport of the
	 * row DTO, not a user choice ({@see \Bitrix\Tasks\V2\Internal\Service\Grid\Replication\Row\Assembler\Field\RowPayloadFieldAssembler}),
	 * so unchecking it in the built-in settings window cannot leave the row without cell content.
	 *
	 * @return string[]
	 */
	public function getVisibleColumnsIds(): array
	{
		$saved = $this->getOptions()->GetVisibleColumns();
		$available = [];
		$default = [];
		foreach ($this->getColumns() as $column)
		{
			$columnId = $column->getId();
			$available[] = $columnId;
			if ($column->isDefault())
			{
				$default[] = $columnId;
			}
		}

		return $this->resolveVisibleColumnsIds($saved, $available, $default);
	}

	/**
	 * @param string[] $saved
	 * @param string[] $available
	 * @param string[] $default
	 * @return string[]
	 */
	private function resolveVisibleColumnsIds(array $saved, array $available, array $default): array
	{
		$scheduleEnabled = $this->isScheduleEnabled();
		$hasSavedPeriod = $this->hasPeriodColumn($saved);
		$hasSavedAttribute = false;
		$ids = [];

		foreach ($available as $id)
		{
			if (str_starts_with($id, ReplicationProvider::PERIOD_COLUMN_PREFIX))
			{
				if ($scheduleEnabled)
				{
					$ids[] = $id;
				}

				continue;
			}

			$isSavedAttribute = $saved !== [] && in_array($id, $saved, true);
			$hasSavedAttribute = $hasSavedAttribute || $isSavedAttribute;

			// NAME carries the row payload every cell overlay reads (DTO-S1), so it is kept
			// regardless of the saved selection: without it the whole row renders empty.
			if (
				($saved === [] && in_array($id, $default, true))
				|| $isSavedAttribute
				|| $id === ReplicationProvider::NAME_COLUMN
			)
			{
				$ids[] = $id;
			}
		}

		$hasValidSavedSelection = $hasSavedAttribute || ($scheduleEnabled && $hasSavedPeriod);

		return $saved === [] || $hasValidSavedSelection ? $ids : $default;
	}

	/**
	 * @param string[] $saved
	 */
	private function hasPeriodColumn(array $saved): bool
	{
		foreach ($saved as $id)
		{
			if (str_starts_with((string)$id, ReplicationProvider::PERIOD_COLUMN_PREFIX))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * Synchronizes personal grid options with the current period and sorting before building
	 * the component.
	 *
	 * The `main.ui.grid` component takes column visibility and pinning from its own options
	 * ({@see \Bitrix\Main\Grid\Options}, the `main.interface.grid` key by GRID_ID in `CUserOptions`),
	 * not from this object. `getVisibleColumnsIds()` and `Column::setSticked(true)` are
	 * only defaults. A saved view contains the previous period's column IDs, so without
	 * synchronization it hides current columns and may leave `NAME` unpinned. Rewriting
	 * the view with {@see getVisibleColumnsIds()} preserves attribute choices while replacing
	 * period IDs with the ones the switch asks for - which is also how the switch persists
	 * itself ({@see isScheduleEnabled()}). Column widths are retained.
	 *
	 * The sorting lives in the same view node (`last_sort_by`/`last_sort_order`) and is written
	 * there by the grid itself on every header click. The section keeps its sorting in its own
	 * settings, so the grid copy is mirrored from them instead of being a second source of
	 * truth: the header state falls back to it for columns the `SORT` component param does not
	 * mention, and a stale value there would put an arrow on a column nobody sorts by. A sorting
	 * without a column (`priority` and the like) mirrors as well - it matches no column, so no
	 * arrow is drawn at all.
	 *
	 * We write to `CUserOptions` only on an actual mismatch: in a settled state (same page/period)
	 * the call does not write to the DB. We compare BEFORE mutating the options
	 * ({@see \CGridOptions::SetColumns()} changes the in-memory state).
	 */
	public function normalizePersistedView(string $sortBy, string $sortDirection): void
	{
		$options = $this->getOptions();

		$desiredColumns = $this->getVisibleColumnsIds();
		$desiredSticked = $this->getStickedColumnsIds();

		$currentColumns = $options->GetVisibleColumns();
		$currentSticked = $options->getStickedColumns() ?? [];
		$currentOptions = $options->getCurrentOptions();

		if (
			$currentColumns === $desiredColumns
			&& $currentSticked === $desiredSticked
			&& ($currentOptions['last_sort_by'] ?? null) === $sortBy
			&& ($currentOptions['last_sort_order'] ?? null) === $sortDirection
		)
		{
			return;
		}

		$options->SetColumns(implode(',', $desiredColumns));
		$options->setStickedColumns($desiredSticked);
		$options->SetSorting($sortBy, $sortDirection);
		$options->save();
	}

	/**
	 * Ids of columns with the provider pinning default ({@see Column::setSticked()}) - currently only `NAME`.
	 *
	 * @return string[]
	 */
	private function getStickedColumnsIds(): array
	{
		$ids = [];
		foreach ($this->getColumns() as $column)
		{
			if ($column->isSticked())
			{
				$ids[] = $column->getId();
			}
		}

		return $ids;
	}

	protected function createRows(): Rows
	{
		return new ReplicationRows(
			$this->getVisibleSegments(),
			$this->getVisibleColumnsIds(),
			new ReplicationRowActions(),
		);
	}

	protected function createPanel(): ?Panel
	{
		return new ReplicationPanel();
	}

	public function setPageNavigation(PageNavigation $pageNavigation): self
	{
		$this->pageNavigation = $pageNavigation;

		return $this;
	}

	protected function createPagination(): ?PageNavigation
	{
		return $this->pageNavigation;
	}
}
