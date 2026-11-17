<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Infrastructure\Controller\Replication;

use Bitrix\Main\Engine\ActionFilter\Attribute\Rule\CloseSession;
use Bitrix\Main\Engine\ActionFilter\Attribute\Rule\ContentType;
use Bitrix\Main\Engine\ActionFilter\FilterType;
use Bitrix\Main\Engine\Response\Component;
use Bitrix\Main\Grid\Action\CalculateTotalCountAction;
use Bitrix\Main\Grid\Component\ComponentParams;
use Bitrix\Main\HttpResponse;
use Bitrix\Main\UI\PageNavigation;
use Bitrix\Main\Web\Json;
use Bitrix\Tasks\V2\Infrastructure\Controller\ActionFilter\Rule\RegularTasksSectionEnabled;
use Bitrix\Tasks\V2\Infrastructure\Controller\BaseController;
use Bitrix\Tasks\V2\Internal\Service\Grid\Replication\Column\ColumnSortMap;
use Bitrix\Tasks\V2\Internal\Service\Grid\Replication\ReplicationGrid;
use Bitrix\Tasks\V2\Internal\Service\Grid\Replication\Settings\ReplicationGridSettings;
use Bitrix\Tasks\V2\Internal\Service\Grid\Replication\Stub\StubService;
use Bitrix\Tasks\V2\Internal\Service\Replication\Schedule\PeriodColumns;
use Bitrix\Tasks\V2\Internal\Service\Replication\Schedule\ScheduleProjectionService;
use Bitrix\Tasks\V2\Internal\Service\Replication\Section\ReplicationRowService;
use Bitrix\Tasks\V2\Internal\Service\Replication\Section\SectionActivity;
use Bitrix\Tasks\V2\Internal\Service\Replication\Section\SectionListQuery;
use Bitrix\Tasks\V2\Internal\Service\Replication\Section\SectionRole;
use Bitrix\Tasks\V2\Internal\Service\Replication\Section\SectionSettings;

/**
 * Server grid controller of the "Regular tasks" section (P1.T3).
 *
 * Repeats the mechanics of the precedent {@see \Bitrix\Tasks\V2\Infrastructure\Controller\Template\History}:
 * `getGridAction` returns the `bitrix:main.ui.grid` component, `getGridDataAction` - plain
 * HTML for re-requests (pagination/sorting/filter are intercepted on the frontend by
 * `Grid::beforeRequest`). The section flag gate is the {@see RegularTasksSectionEnabled} attribute.
 */
class Grid extends BaseController
{
	/**
	 * Rows per page: the options of the panel dropdown and the size used until the user picks one.
	 *
	 * The autowired {@see PageNavigation} arrives with `range(1, 50)`
	 * ({@see \Bitrix\Main\HttpApplication::registerAutoWirings()}), which turns the dropdown into
	 * a 50-item list. These are the standard grid sizes ({@see \Bitrix\Main\Grid\Grid::getPageSizes()}).
	 */
	private const PAGE_SIZES = [5, 10, 20, 50];
	private const DEFAULT_PAGE_SIZE = 20;
	private const MAX_REALTIME_ROWS = 50;
	private const MAX_REALTIME_PAGE = 1_000_000;
	/**
	 * @ajaxAction tasks.V2.Replication.Grid.getGrid
	 */
	#[RegularTasksSectionEnabled]
	#[CloseSession]
	public function getGridAction(
		ReplicationRowService $rowService,
		ScheduleProjectionService $projectionService,
		PeriodColumns $periodColumns,
		StubService $stubService,
		PageNavigation $pageNavigation,
		string $period = PeriodColumns::WEEK,
		int $anchorTs = 0,
		int $tzOffset = 0,
	): Component
	{
		// Window metadata (DTO-W1) for time navigation on the frontend. Computed from the same
		// normalized period/anchor/tzOffset as the grid columns, so the window bounds match the
		// set of segment columns. Only for the initial load: getGridData does not re-send the window.
		$windowMeta = $periodColumns->resolveWindowMeta(
			PeriodColumns::isValidPeriod($period) ? $period : PeriodColumns::WEEK,
			$anchorTs > 0 ? $anchorTs : time(),
			$tzOffset,
		);

		return $this->buildGridComponent(
			$rowService,
			$projectionService,
			$periodColumns,
			$stubService,
			$pageNavigation,
			$period,
			$anchorTs,
			$tzOffset,
			['window' => $windowMeta],
		);
	}

	/**
	 * @ajaxAction tasks.V2.Replication.Grid.getGridData
	 */
	#[RegularTasksSectionEnabled]
	#[ContentType(type: FilterType::DisablePrefilter)]
	#[CloseSession]
	public function getGridDataAction(
		ReplicationRowService $rowService,
		ScheduleProjectionService $projectionService,
		PeriodColumns $periodColumns,
		StubService $stubService,
		PageNavigation $pageNavigation,
		string $period = PeriodColumns::WEEK,
		int $anchorTs = 0,
		int $tzOffset = 0,
	): HttpResponse
	{
		$component = $this->buildGridComponent(
			$rowService,
			$projectionService,
			$periodColumns,
			$stubService,
			$pageNavigation,
			$period,
			$anchorTs,
			$tzOffset,
		);

		$content = Json::decode($component->getContent());

		return (new HttpResponse())->setContent($content['data']['html'] ?? '');
	}

	/**
	 * Prepared data for updating existing grid rows without rebuilding the grid.
	 *
	 * @ajaxAction tasks.V2.Replication.Grid.getGridRows
	 */
	#[RegularTasksSectionEnabled]
	#[CloseSession]
	public function getGridRowsAction(
		ReplicationRowService $rowService,
		ScheduleProjectionService $projectionService,
		PeriodColumns $periodColumns,
		array $templateIds = [],
		array $currentPageTemplateIds = [],
		int $page = 1,
		string $period = PeriodColumns::WEEK,
		int $anchorTs = 0,
		int $tzOffset = 0,
	): array
	{
		$templateIds = $this->normalizeTemplateIds($templateIds);
		$currentPageTemplateIds = $this->normalizeTemplateIds($currentPageTemplateIds);
		if ($templateIds === [] || $currentPageTemplateIds === [])
		{
			return $this->gridRowsReloadResponse();
		}

		$normalizedPeriod = PeriodColumns::isValidPeriod($period) ? $period : PeriodColumns::WEEK;
		$normalizedAnchorTs = $anchorTs > 0 ? $anchorTs : time();
		$segments = $periodColumns->build($normalizedPeriod, $normalizedAnchorTs, $tzOffset);

		$grid = new ReplicationGrid(new ReplicationGridSettings(), $segments, $this->resolveScheduleEnabled());
		$pageSize = $this->resolvePageSize($grid);
		$page = min(max(1, $page), self::MAX_REALTIME_PAGE);
		$sectionSettings = $this->resolveSectionSettings();

		$pageQuery = $this->buildSectionQuery(
			sectionSettings: $sectionSettings,
			limit: $pageSize,
			offset: ($page - 1) * $pageSize,
			visibleColumnIds: $grid->getVisibleColumnsIds(),
		);
		$actualPageTemplateIds = $rowService->getPageTemplateIds($pageQuery);

		// Page membership/order or a group header may have changed. A row-only update
		// cannot fix these cases without leaving the grid inconsistent.
		if (
			$actualPageTemplateIds !== $currentPageTemplateIds
			|| $sectionSettings->groupBy !== SectionSettings::DEFAULT_GROUP_BY
		)
		{
			return $this->gridRowsReloadResponse();
		}

		$visibleTemplateIds = array_values(array_intersect($templateIds, $actualPageTemplateIds));
		if (count($visibleTemplateIds) !== count($templateIds))
		{
			return $this->gridRowsReloadResponse();
		}

		$rowsQuery = $this->buildSectionQuery(
			sectionSettings: $sectionSettings,
			limit: count($visibleTemplateIds),
			offset: 0,
			templateIds: $visibleTemplateIds,
			visibleColumnIds: $grid->getVisibleColumnsIds(),
		);
		$rawRows = $rowService->getRows($rowsQuery)['rows'];
		if (count($rawRows) !== count($visibleTemplateIds))
		{
			return $this->gridRowsReloadResponse();
		}

		$window = $periodColumns->resolve($normalizedPeriod, $normalizedAnchorTs, $tzOffset);
		$grid->setRawRows($this->attachSchedule($rawRows, $grid, $projectionService, $window, $tzOffset));

		$rows = [];
		foreach ($grid->prepareRows() as $row)
		{
			$id = (int)($row['id'] ?? 0);
			if ($id > 0)
			{
				$rows[] = [
					'id' => $id,
					'columns' => $row['columns'] ?? [],
					'actions' => $this->prepareRowActions($row['actions'] ?? []),
				];
			}
		}

		return [
			'requiresReload' => false,
			'rows' => $rows,
		];
	}

	private function buildGridComponent(
		ReplicationRowService $rowService,
		ScheduleProjectionService $projectionService,
		PeriodColumns $periodColumns,
		StubService $stubService,
		PageNavigation $pageNavigation,
		string $period,
		int $anchorTs,
		int $tzOffset,
		array $additionalResponseParams = [],
	): Component
	{
		// The column set is server-side and depends on the period: week->days, month->weeks,
		// quarter->3 months, year->12 months (PER-01). A period change reloads the grid,
		// so period/anchor come to getGridDataAction too (the same path).
		//
		// period/anchorTs/tzOffset - action parameters, not $request->get(): the section
		// sends them in the JSON body (apiClient contentType=json), and $request->get()
		// exposes only GET+POST, never the decoded JSON. Same binding as the Schedule
		// controller - reading them via $request->get() here silently pinned the grid to
		// the WEEK default, so columns showed week labels for every period.
		$normalizedPeriod = PeriodColumns::isValidPeriod($period) ? $period : PeriodColumns::WEEK;
		$normalizedAnchorTs = $anchorTs > 0 ? $anchorTs : time();

		$segments = $periodColumns->build($normalizedPeriod, $normalizedAnchorTs, $tzOffset);

		$grid = new ReplicationGrid(new ReplicationGridSettings(), $segments, $this->resolveScheduleEnabled());

		$sectionSettings = $this->resolveSectionSettings();

		// The main.ui.grid component takes column visibility, pinning and the sorting of its
		// header from personal options (CUserOptions), not from this object; we reset them to
		// the server default and to the section settings so that neither the dynamic column set
		// and NAME pinning nor the sorting arrow are broken by a stale view (P3.T1).
		$grid->normalizePersistedView($sectionSettings->sortBy, $sectionSettings->sortDirection);

		// The page size is personal: the panel dropdown saves it into the grid options
		// (`page_size` of `main.interface.grid`) and reloads the grid, so the size has to be read
		// back from there. A constant size ignored the choice - every request returned the same
		// rows and the pagination stayed on a single page.
		$pageNavigation->setPageSizes(self::PAGE_SIZES);
		$pageNavigation->setPageSize($this->resolvePageSize($grid));
		$grid->setPageNavigation($pageNavigation);

		$sectionQuery = $this->buildSectionQuery(
			sectionSettings: $sectionSettings,
			limit: $pageNavigation->getLimit(),
			offset: $pageNavigation->getOffset(),
			visibleColumnIds: $grid->getVisibleColumnsIds(),
		);

		// The exact total behind the panel's "Show count": a separate COUNT, so a page costs
		// one query and the total is paid for only when the user asks for it.
		$grid->setTotalCountCalculator(static fn (): int => $rowService->getTotalCount($sectionQuery));

		// The native grid sends its own actions to this endpoint with `grid_action` in the query.
		// "Show count" is the only one handled on the server - the platform action answers with
		// the JSON its widget expects and ends the request; the rest belongs to the section
		// frontend, so the grid actions of the panel and rows are not processed here.
		if ($this->getRequest()->get('grid_action') === CalculateTotalCountAction::getId())
		{
			$grid->processRequest();
		}

		$result = $rowService->getRows($sectionQuery);

		// The record count of the navigation is the lookahead, not the total: page rows plus one
		// when a next page exists. It drives the page list; the exact total is the counter above.
		$pageNavigation->setRecordCount($pageNavigation->getOffset() + count($result['rows']) + ($result['hasMore'] ? 1 : 0));

		// The schedule projection arrives with the grid: previously the frontend fetched it
		// separately (API-S3 Replication.Schedule.get) after rendering the rows. The page cells
		// have already passed the visibility filter in getRows, so the projection is computed
		// right over them (the same PER-01 window as the columns) and embedded into each row -
		// the cell overlay hydrates it from the DOM without an extra request.
		$window = $periodColumns->resolve($normalizedPeriod, $normalizedAnchorTs, $tzOffset);

		$grid->setRawRows($this->attachSchedule($result['rows'], $grid, $projectionService, $window, $tzOffset));

		$componentParams = [
			// All grid Ajax is intercepted on the frontend (Grid::beforeRequest), so the
			// component's own Ajax is disabled.
			'AJAX_MODE' => 'N',
			// The menu picks the attribute columns. Dynamic period columns are hidden from it
			// by ScheduleColumnsSetting: the graph is switched by the control bar toggle only.
			'SHOW_GRID_SETTINGS_MENU' => true,
			// Column order is owned by the server: the period columns are rebuilt for the
			// current period and normalizePersistedView() rewrites the personal view, so a
			// dragged order would not survive the next request anyway.
			'ALLOW_COLUMNS_SORT' => false,
			// Native pinning of the left columns: NAME (setSticked) stays on the left during
			// horizontal scroll together with the checkbox/menu (P3.T1).
			// Boolean only: the component reads the flag via Grid\Params::prepareBoolean(),
			// which accepts only is_bool(); the string 'Y' silently falls back to false.
			'ALLOW_STICKED_COLUMNS' => true,
			// The section fits the grid area into the window, so the rows scroll inside
			// the grid and the header would otherwise leave with them.
			'ALLOW_PIN_HEADER' => true,
			// The dropdown label falls back to this param while the grid options hold no
			// page size, so it has to match the default the pagination uses.
			'DEFAULT_PAGE_SIZE' => self::DEFAULT_PAGE_SIZE,
			// "Total" is a "Show count" link instead of a number: the record count of the
			// navigation is the lookahead and would read as an exact total.
			'TOTAL_ROWS_COUNT_HTML' => $grid->getTotalRowsCountHtml(),
			// The sorting arrow of the header comes from the section settings, so a header
			// click and the gear menu show one and the same state. A sorting without a
			// column matches no header - then no arrow is drawn.
			'SORT' => [$sectionSettings->sortBy => $sectionSettings->sortDirection],
		];
		if ($result['rows'] === [])
		{
			$componentParams['STUB'] = $stubService->resolve(
				rulesExist: fn (): bool => $rowService->getSliceTemplateIds(
					new SectionListQuery(viewerId: $this->userId, replicateActive: null),
					1,
				) !== [],
				query: $sectionQuery,
				gridId: ReplicationGridSettings::GRID_ID,
			);
		}

		return new Component(
			componentName: 'bitrix:main.ui.grid',
			componentParams: ComponentParams::get($grid, $componentParams),
			additionalResponseParams: $additionalResponseParams,
		);
	}

	/**
	 * Page size chosen by the user in the grid panel, or the section default.
	 *
	 * A stale or hand-made value (the previous option list allowed 1..50) falls back to the
	 * default instead of being obeyed.
	 */
	private function resolvePageSize(ReplicationGrid $grid): int
	{
		$pageSize = (int)($grid->getOptions()->GetNavParams()['nPageSize'] ?? 0);

		return in_array($pageSize, self::PAGE_SIZES, true) ? $pageSize : self::DEFAULT_PAGE_SIZE;
	}

	/**
	 * Section settings of the current request: the confirmed payload of the frontend, overridden
	 * by a click on a column header.
	 *
	 * A click arrives as the native grid `by`/`order` pair, where `by` is the sorting the column
	 * was marked with ({@see ColumnSortMap}). Only a value of that map is accepted, so no ORM
	 * field name can reach the query from the request; an unknown `by` is ignored and the
	 * settings sorting stays as it is. Everything else is normalized by
	 * {@see SectionSettings::createFromStored()} against the same allowlist.
	 */
	private function resolveSectionSettings(): SectionSettings
	{
		$stored = [
			'groupBy' => $this->requestValue('groupBy'),
			'sortBy' => $this->requestValue('sortBy'),
			'sortDirection' => $this->requestValue('sortDirection'),
		];

		$headerSortBy = $this->requestValue('by');
		if (is_string($headerSortBy) && ColumnSortMap::columnIdBySortBy($headerSortBy) !== null)
		{
			$stored['sortBy'] = $headerSortBy;
			$stored['sortDirection'] = $this->requestValue('order');
		}

		return SectionSettings::createFromStored($stored);
	}

	/**
	 * State of the "Schedule graph" switch as the section sends it, or null when the request
	 * carries none - then the state saved in the personal grid view stands.
	 *
	 * The grid path posts the payload as a form, so the boolean arrives as a string there.
	 */
	private function resolveScheduleEnabled(): ?bool
	{
		$value = $this->requestValue('scheduleEnabled');
		if ($value === null)
		{
			return null;
		}

		return $value === true || $value === 'true' || $value === 'Y' || $value === '1' || $value === 1;
	}

	/**
	 * @param int[] $templateIds
	 */
	private function buildSectionQuery(
		SectionSettings $sectionSettings,
		int $limit,
		int $offset,
		array $templateIds = [],
		array $visibleColumnIds = [],
	): SectionListQuery
	{
		return new SectionListQuery(
			viewerId: $this->userId,
			roleFilter: $this->resolveRoleFilter(),
			replicateActive: $this->resolveReplicateActive(),
			limit: $limit,
			offset: $offset,
			search: $this->resolveSearch(),
			groupBy: $sectionSettings->groupBy,
			sortBy: $sectionSettings->sortBy,
			sortDirection: $sectionSettings->sortDirection,
			templateIds: $templateIds,
			filter: $this->resolveFilter(),
			visibleColumnIds: $visibleColumnIds,
		);
	}

	private function resolveFilter(): array
	{
		return SectionFilter::normalize($this->requestValue('filter'));
	}

	/**
	 * @return int[]
	 */
	private function normalizeTemplateIds(array $templateIds): array
	{
		$normalized = [];
		foreach ($templateIds as $templateId)
		{
			$templateId = (int)$templateId;
			if ($templateId > 0)
			{
				$normalized[$templateId] = $templateId;
			}
		}

		return array_slice(array_values($normalized), 0, self::MAX_REALTIME_ROWS);
	}

	private function gridRowsReloadResponse(): array
	{
		return [
			'requiresReload' => true,
			'rows' => [],
		];
	}

	/**
	 * Converts Grid action controls to the client popup format, as main.ui.grid does
	 * in its component compatibility layer during a full render.
	 */
	private function prepareRowActions(array $actions): array
	{
		$propertyMap = [
			'TEXT' => 'text',
			'ONCLICK' => 'onclick',
			'ICONCLASS' => 'className',
			'TITLE' => 'title',
			'HREF' => 'href',
			'DEFAULT' => 'default',
		];

		foreach ($actions as &$action)
		{
			foreach ($propertyMap as $source => $target)
			{
				if (array_key_exists($source, $action))
				{
					$action[$target] = $action[$source];
					unset($action[$source]);
				}
			}
		}
		unset($action);

		return $actions;
	}

	/**
	 * Embeds the schedule projection for the visible page rows into the raw rows (the
	 * `SCHEDULE_CELL` key - DTO-S2). {@see \Bitrix\Tasks\V2\Internal\Service\Grid\Replication\Row\Assembler\Field\PeriodCellFieldAssembler}
	 * embeds each cell bucket into its anchor, so the frontend needs no separate API-S3.
	 *
	 * With the schedule graph off the section is a plain list of recurrence rules: there is no
	 * segment cell to fill and no graph empty state to decide on (AC-S-073 belongs to the graph),
	 * so the projection is not computed at all.
	 *
	 * @param array<int, array> $rows
	 * @param array{scale: string, from: int, to: int} $window
	 * @return array<int, array>
	 */
	private function attachSchedule(
		array $rows,
		ReplicationGrid $grid,
		ScheduleProjectionService $projectionService,
		array $window,
		int $tzOffset,
	): array
	{
		if (!$grid->isScheduleEnabled())
		{
			return $rows;
		}

		$templateIds = array_map(static fn (array $row): int => (int)($row['ID'] ?? 0), $rows);

		$cellsByTemplate = [];
		foreach (
			$projectionService->getCells(
				$this->userId,
				$templateIds,
				$window['scale'],
				$window['from'],
				$window['to'],
				$tzOffset,
			) as $cell
		)
		{
			$cellsByTemplate[$cell->templateId] = $cell->toArray();
		}

		foreach ($rows as &$row)
		{
			$row['SCHEDULE_CELL'] = $cellsByTemplate[(int)($row['ID'] ?? 0)] ?? null;
		}
		unset($row);

		return $rows;
	}

	private function resolveRoleFilter(): ?string
	{
		$role = $this->requestValue('roleId');

		return SectionRole::resolveMemberType(is_string($role) ? $role : null);
	}

	private function resolveReplicateActive(): ?bool
	{
		$replicateStatus = $this->requestValue('replicateStatus');

		return SectionActivity::resolve(
			is_string($replicateStatus) ? $replicateStatus : null,
			$this->resolveLegacyReplicateActive(),
		);
	}

	private function resolveLegacyReplicateActive(): bool
	{
		$value = $this->requestValue('replicateActive');

		if ($value === null)
		{
			return true; // preselected "Active recurrence" (AC-S-003)
		}

		return $value === true || $value === 'true' || $value === 'Y' || $value === '1' || $value === 1;
	}

	private function resolveSearch(): ?string
	{
		$search = $this->requestValue('search');
		if (!is_string($search))
		{
			return null;
		}

		$search = trim($search);

		return $search === '' ? null : $search;
	}

	/**
	 * Request value with a fallback to the decoded JSON body. The section posts the filter
	 * payload as JSON (apiClient contentType=json) on getGrid (initial load, period switch),
	 * which $request->get() does not expose; on the form-POST grid path (getGridData) the
	 * value comes from GET/POST as usual. Without the fallback the role/active/search filter
	 * was silently dropped on those paths.
	 */
	private function requestValue(string $name): mixed
	{
		$value = $this->getRequest()->get($name);

		return $value ?? $this->getRequest()->getJsonList()->get($name);
	}

}
