<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Infrastructure\Grid\Shared\Realtime;

use Bitrix\Main\Grid;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\UI\Filter;
use Bitrix\Socialnetwork\V2\Infrastructure\Grid\Project\ProjectGrid;
use Bitrix\Socialnetwork\V2\Infrastructure\Grid\Scrum\ScrumGrid;
use Bitrix\Socialnetwork\V2\Internal\Integration\Tasks\Service\ProjectCounterFilterService;
use Bitrix\Socialnetwork\V2\Internal\Repository\FavoritesRepositoryInterface;
use Bitrix\Socialnetwork\V2\Internal\Repository\WorkgroupFilterRepositoryInterface;
use Bitrix\Socialnetwork\V2\Public\Provider\Params\Project\ProjectGridFilter;
use Bitrix\Socialnetwork\V2\Public\Provider\Params\Scrum\ScrumGridFilter;

class TaskRealtimeGridRuntimeFactory
{
	private const DEFAULT_PAGE_SIZE = 20;

	private const GRID_COLUMN_TO_FIELD = [
		'ID' => 'ID',
		'NAME' => 'NAME',
		'NUMBER_OF_MEMBERS' => 'NUMBER_OF_MEMBERS',
		'DATE_CREATE' => 'DATE_CREATE',
		'DATE_ACTIVITY' => 'DATE_ACTIVITY',
		'ACTIVITY_DATE' => 'ACTIVITY_DATE',
		'DATE_RELATION' => 'DATE_RELATION',
		'DATE_VIEW' => 'DATE_VIEW',
		'PROJECT_DATE_START' => 'PROJECT_DATE_START',
		'PROJECT_DATE_FINISH' => 'PROJECT_DATE_FINISH',
		'CLOSED' => 'CLOSED',
	];

	public function __construct(
		private readonly FavoritesRepositoryInterface $favoritesRepository,
		private readonly WorkgroupFilterRepositoryInterface $workgroupFilterRepository,
		private readonly ProjectCounterFilterService $projectCounterFilterService,
	)
	{
	}

	public function createProjectRuntime(
		TaskRealtimePageContext $pageContext,
		int $page = 1,
		int $currentUserId = 0,
	): ?TaskRealtimeGridRuntime
	{
		$gridId = trim($pageContext->gridId);
		if ($gridId === '')
		{
			return null;
		}

		$filterId = $this->normalizeFilterId($gridId, $pageContext->filterId);
		$contextUserId = $this->normalizeTaskRealtimeContextUserId(
			$pageContext->contextUserId,
			$currentUserId,
		);
		$grid = new ProjectGrid(
			settings: $this->createGridSettings($gridId, $filterId),
			currentUserId: $currentUserId,
			isProjectMode: true,
			contextUserId: $contextUserId,
			filterId: $filterId,
		);
		$visibleColumns = $grid->getVisibleColumnsIds();
		$pageSize = $this->resolvePageSize($gridId);

		return new TaskRealtimeGridRuntime(
			grid: $grid,
			filter: $this->createProjectRealtimeFilter(
				grid: $grid,
				filterId: $filterId,
				currentUserId: $currentUserId,
				subjectId: $pageContext->subjectId,
			),
			sort: $this->mapGridSortToFields($grid->getOrmOrder()),
			offset: $this->resolvePageOffset($page, $pageSize),
			limit: $pageSize,
			select: $this->getSelectFieldsFromGrid($visibleColumns),
			contextUserId: $contextUserId,
			isScrum: false,
			withImage: in_array('NAME', $visibleColumns, true),
			withMembers: $this->shouldLoadMembers($visibleColumns),
			withOwner: in_array('OWNER', $visibleColumns, true),
			withTags: in_array('TAGS', $visibleColumns, true),
			withRelationDate: in_array('DATE_RELATION', $visibleColumns, true),
			withViewDate: in_array('DATE_VIEW', $visibleColumns, true),
		);
	}

	public function createScrumRuntime(
		TaskRealtimePageContext $pageContext,
		int $page = 1,
		int $currentUserId = 0,
	): ?TaskRealtimeGridRuntime
	{
		$gridId = trim($pageContext->gridId);
		if ($gridId === '')
		{
			return null;
		}

		$filterId = $this->normalizeFilterId($gridId, $pageContext->filterId);
		$contextUserId = $this->normalizeTaskRealtimeContextUserId(
			$pageContext->contextUserId,
			$currentUserId,
		);
		$grid = new ScrumGrid(
			settings: $this->createGridSettings($gridId, $filterId),
			currentUserId: $currentUserId,
			contextUserId: $contextUserId,
			filterId: $filterId,
		);
		$visibleColumns = $grid->getVisibleColumnsIds();
		$pageSize = $this->resolvePageSize($gridId);

		return new TaskRealtimeGridRuntime(
			grid: $grid,
			filter: $this->createScrumRealtimeFilter(
				grid: $grid,
				filterId: $filterId,
				currentUserId: $currentUserId,
			),
			sort: $this->mapGridSortToFields($grid->getOrmOrder()),
			offset: $this->resolvePageOffset($page, $pageSize),
			limit: $pageSize,
			select: $this->getSelectFieldsFromGrid($visibleColumns),
			contextUserId: $contextUserId,
			isScrum: true,
			withImage: in_array('NAME', $visibleColumns, true),
			withMembers: $this->shouldLoadMembers($visibleColumns),
			withOwner: in_array('OWNER', $visibleColumns, true),
		);
	}

	private function createGridSettings(string $gridId, string $filterId): Grid\Settings
	{
		return new Grid\Settings([
			'ID' => $gridId,
			'FILTER_ID' => $filterId,
			'PRESETS' => [],
		]);
	}

	private function normalizeFilterId(string $gridId, string $filterId): string
	{
		$filterId = trim($filterId);

		return ($filterId !== '' ? $filterId : $gridId);
	}

	private function normalizeTaskRealtimeContextUserId(int $contextUserId, int $currentUserId): int
	{
		return ($contextUserId > 0 ? $contextUserId : max(0, $currentUserId));
	}

	private function resolvePageOffset(int $page, int $pageSize): int
	{
		$page = max(1, $page);
		$pageSize = max(1, $pageSize);

		return ($page - 1) * $pageSize;
	}

	private function resolvePageSize(string $gridId, int $defaultPageSize = self::DEFAULT_PAGE_SIZE): int
	{
		$gridId = trim($gridId);
		if ($gridId === '')
		{
			return $defaultPageSize;
		}

		$gridOptions = new Grid\Options($gridId);
		$navParams = $gridOptions->getNavParams(['nPageSize' => $defaultPageSize]);
		$pageSize = (int)($navParams['nPageSize'] ?? $defaultPageSize);

		return ($pageSize > 0 ? $pageSize : $defaultPageSize);
	}

	private function createProjectRealtimeFilter(
		ProjectGrid $grid,
		string $filterId,
		int $currentUserId,
		int $subjectId,
	): ConditionTree
	{
		$filter = new ProjectGridFilter(
			filterOptions: new Filter\Options($filterId),
			filterFields: $grid->getFilter()?->getFieldArrays() ?? [],
			workgroupFilterRepository: $this->workgroupFilterRepository,
			favoritesRepository: $this->favoritesRepository,
			projectCounterFilterService: $this->projectCounterFilterService,
			currentUserId: $currentUserId,
			subjectId: $subjectId,
		);

		return $filter->prepareFilter();
	}

	private function createScrumRealtimeFilter(
		ScrumGrid $grid,
		string $filterId,
		int $currentUserId,
	): ConditionTree
	{
		$filter = new ScrumGridFilter(
			filterOptions: new Filter\Options($filterId),
			filterFields: $grid->getFilter()?->getFieldArrays() ?? [],
			workgroupFilterRepository: $this->workgroupFilterRepository,
			favoritesRepository: $this->favoritesRepository,
			projectCounterFilterService: $this->projectCounterFilterService,
			currentUserId: $currentUserId,
		);

		return $filter->prepareFilter();
	}

	private function shouldLoadMembers(array $visibleColumns): bool
	{
		return in_array('MEMBERS', $visibleColumns, true);
	}

	private function getSelectFieldsFromGrid(array $visibleColumns): array
	{
		$fields = ['ID', 'CLOSED', 'OPENED', 'VISIBLE', 'TYPE', 'SCRUM_MASTER_ID'];

		foreach ($visibleColumns as $columnId)
		{
			if (isset(self::GRID_COLUMN_TO_FIELD[$columnId]))
			{
				$fields[] = self::GRID_COLUMN_TO_FIELD[$columnId];
			}
		}

		if (in_array('NAME', $visibleColumns, true))
		{
			$fields[] = 'IMAGE_ID';
		}

		if (in_array('MEMBERS', $visibleColumns, true))
		{
			$fields[] = 'NUMBER_OF_MEMBERS';
		}

		return array_values(array_unique($fields));
	}

	private function mapGridSortToFields(array $gridSort): array
	{
		$result = [];
		foreach ($gridSort as $columnId => $direction)
		{
			$field = self::GRID_COLUMN_TO_FIELD[$columnId] ?? null;
			if ($field !== null)
			{
				$result[$field] = $direction;
			}
		}

		return $result;
	}
}
