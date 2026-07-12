<?php

declare(strict_types=1);

if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Filter;
use Bitrix\Main\Context;
use Bitrix\Main\Grid\Component\ComponentParams;
use Bitrix\Main\Grid\Grid;
use Bitrix\Main\Grid\Options;
use Bitrix\Main\Grid\Settings;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Provider\Params\Pager;
use Bitrix\Main\UI\Extension;
use Bitrix\Main\UI\Filter\Theme;
use Bitrix\Main\UI\PageNavigation;
use Bitrix\Socialnetwork\Helper\Feature;
use Bitrix\Socialnetwork\Helper\Workgroup\Subject;
use Bitrix\Socialnetwork\Internals\EventService\Push\PullDictionary;
use Bitrix\Socialnetwork\Item\Workgroup\Type;
use Bitrix\Socialnetwork\V2\Infrastructure\Filter\Project\ProjectFilter;
use Bitrix\Socialnetwork\V2\Infrastructure\Filter\Scrum\ScrumFilter;
use Bitrix\Socialnetwork\V2\Infrastructure\Filter\Workgroup\WorkgroupFilter;
use Bitrix\Socialnetwork\V2\Infrastructure\Filter\Workgroup\UserWorkgroupFilter;
use Bitrix\Socialnetwork\V2\Infrastructure\Grid\Project\ProjectGrid;
use Bitrix\Socialnetwork\V2\Infrastructure\Grid\Scrum\ScrumGrid;
use Bitrix\Socialnetwork\V2\Infrastructure\Grid\Shared\Realtime\TaskRealtimePageContext;
use Bitrix\Socialnetwork\V2\Infrastructure\Grid\Shared\Realtime\TaskRealtimePageContextSigner;
use Bitrix\Socialnetwork\V2\Infrastructure\Grid\Shared\Url\WorkgroupActionUrlProvider;
use Bitrix\Socialnetwork\V2\Infrastructure\Grid\Shared\Url\WorkgroupViewUrlProvider;
use Bitrix\Socialnetwork\V2\Infrastructure\Grid\Workgroup\WorkgroupGrid;
use Bitrix\Socialnetwork\V2\Public\Provider\Params\Project\AbstractProjectFilter;
use Bitrix\Socialnetwork\V2\Public\Provider\Params\Project\ProjectGridFilter;
use Bitrix\Socialnetwork\V2\Public\Provider\Params\Project\ProjectParams;
use Bitrix\Socialnetwork\V2\Public\Provider\Params\Scrum\ScrumGridFilter;
use Bitrix\Socialnetwork\V2\Public\Provider\Params\Workgroup\WorkgroupGridFilter;
use Bitrix\Socialnetwork\V2\Public\Provider\Params\Project\ProjectSelect;
use Bitrix\Socialnetwork\V2\Public\Provider\Params\Project\ProjectSort;
use Bitrix\Socialnetwork\V2\Public\Grid\PinMode;
use Bitrix\Socialnetwork\V2\Public\Mapper\PinModeMapper;
use Bitrix\Socialnetwork\V2\Internal\DI\Container;
use Bitrix\Socialnetwork\V2\Internal\Integration\Tasks\Service\ProjectEfficiencyService;
use Bitrix\Socialnetwork\V2\Internal\Entity\Workgroup\WorkgroupPinMode;
use Bitrix\Main\Web\Uri;
use Bitrix\Socialnetwork\Component\WorkgroupList\TasksCounter;
use Bitrix\Socialnetwork\Helper\Workgroup\Access;
use Bitrix\Socialnetwork\Promotion\ProjectAi;
use Bitrix\Socialnetwork\V2\Internal\Entity\User\Role;
use Bitrix\Socialnetwork\V2\Internal\Entity\Workgroup\WorkgroupUserRelation;
use Bitrix\UI\Buttons\Button;
use Bitrix\UI\Buttons\Color;
use Bitrix\UI\Buttons\JsCode;
use Bitrix\UI\Toolbar\ButtonLocation;
use Bitrix\UI\Toolbar\Facade\Toolbar;

Loader::includeModule('socialnetwork');
Loader::includeModule('ui');

Extension::load([
	'ui.label',
	'ui.counter',
	'ui.hint',
	'im.public',
]);

Loc::loadMessages(__FILE__);

class SocialnetworkProjectListComponent extends CBitrixComponent
{
	private const PAGE_SIZE = 20;
	private const NAV_ID = 'page';
	private const TASK_REALTIME_STRATEGY_NONE = 'none';
	private const TASK_REALTIME_STRATEGY_RELOAD_ONLY = 'reload_only';
	private const TASK_REALTIME_STRATEGY_SELF_NARROW = 'self_narrow';

	private const GRID_COLUMN_TO_FIELD = [
		'ID' => 'id',
		'NAME' => 'name',
		'NUMBER_OF_MEMBERS' => 'numberOfMembers',
		'DATE_CREATE' => 'dateCreate',
		'DATE_ACTIVITY' => 'dateActivity',
		'ACTIVITY_DATE' => 'activityDate',
		'DATE_RELATION' => 'dateRelation',
		'DATE_VIEW' => 'dateView',
		'PROJECT_DATE_START' => 'projectDateStart',
		'PROJECT_DATE_FINISH' => 'projectDateFinish',
		'CLOSED' => 'closed',
	];

	private string $mode = 'project';
	private Grid $grid;
	private ProjectFilter|ScrumFilter|WorkgroupFilter|UserWorkgroupFilter $filter;
	private PageNavigation $pageNavigation;
	private ProjectParams $projectParams;
	private int $totalCount = 0;
	private ?array $rows = null;
	private ?WorkgroupActionUrlProvider $actionUrlProvider = null;
	private ?WorkgroupViewUrlProvider $viewUrlProvider = null;

	protected function listKeysSignedParameters(): array
	{
		return [
			'PATH_TO_USER',
			'NAME_TEMPLATE',
		];
	}

	private function isScrum(): bool
	{
		return $this->mode === 'scrum';
	}

	private function isCommon(): bool
	{
		return $this->mode === 'common';
	}

	private function isUser(): bool
	{
		return $this->mode === 'user';
	}

	private function isCommonOrUser(): bool
	{
		return $this->isCommon() || $this->isUser();
	}

	private function getActionPrefix(): string
	{
		return match (true) {
			$this->isScrum() => 'socialnetwork.v2.Scrum',
			default => 'socialnetwork.v2.LegacyGroup',
		};
	}

	private function getEntityParam(): string
	{
		return match (true) {
			$this->isScrum() => 'scrumId',
			default => 'legacyGroupId',
		};
	}

	private function getTaskRealtimeActions(): array
	{
		if ($this->isCommonOrUser())
		{
			return [];
		}

		return $this->isScrum()
			? [
				'getTaskCounters' => 'socialnetwork.v2.ProjectListGridRealtime.getScrumTaskCounters',
				'getTaskGridRows' => 'socialnetwork.v2.ProjectListGridRealtime.getScrumTaskGridRows',
			]
			: [
				'getTaskCounters' => 'socialnetwork.v2.ProjectListGridRealtime.getProjectTaskCounters',
				'getTaskGridRows' => 'socialnetwork.v2.ProjectListGridRealtime.getProjectTaskGridRows',
			]
		;
	}

	private function getTaskRealtimeStrategy(int $currentUserId, int $contextUserId): string
	{
		if ($this->isCommonOrUser())
		{
			return self::TASK_REALTIME_STRATEGY_NONE;
		}

		return ($contextUserId > 0 && $contextUserId !== $currentUserId)
			? self::TASK_REALTIME_STRATEGY_RELOAD_ONLY
			: self::TASK_REALTIME_STRATEGY_SELF_NARROW
		;
	}

	private function hasTaskRealtime(string $taskRealtimeStrategy): bool
	{
		return $taskRealtimeStrategy !== self::TASK_REALTIME_STRATEGY_NONE;
	}

	private function usesLegacyTasksPull(string $taskRealtimeStrategy): bool
	{
		return $taskRealtimeStrategy === self::TASK_REALTIME_STRATEGY_SELF_NARROW;
	}

	private function getRealtimeCapabilities(string $taskRealtimeStrategy): array
	{
		return [
			'sharedLifecycle' => true,
			'personalPreferences' => true,
			'tasksCounters' => $this->hasTaskRealtime($taskRealtimeStrategy),
			'legacyUserCounters' => false,
			'sidePanelFallback' => true,
		];
	}

	private function getRowUrlContext(): array
	{
		return [
			'mode' => (string)($this->arParams['MODE'] ?? ''),
			'pathToGroup' => (string)($this->arParams['PATH_TO_GROUP'] ?? ''),
			'pathToGroupTasks' => (string)($this->arParams['PATH_TO_GROUP_TASKS'] ?? ''),
			'pathToGroupEdit' => (string)($this->arParams['PATH_TO_GROUP_EDIT'] ?? ''),
			'pathToGroupDelete' => (string)($this->arParams['PATH_TO_GROUP_DELETE'] ?? ''),
			'pathToLeaveGroup' => (string)($this->arParams['PATH_TO_LEAVE_GROUP'] ?? ''),
		];
	}

	private function getRealtimeUiContext(): array
	{
		return [
			'pageSize' => $this->pageNavigation->getPageSize(),
		];
	}

	private function getSignedPageContext(int $contextUserId, string $taskRealtimeStrategy, string $gridId): string
	{
		if (!$this->hasTaskRealtime($taskRealtimeStrategy))
		{
			return '';
		}

		return TaskRealtimePageContextSigner::sign(
			new TaskRealtimePageContext(
				mode: $this->mode,
				taskRealtimeStrategy: $taskRealtimeStrategy,
				contextUserId: $contextUserId,
				subjectId: ($this->isScrum() ? 0 : (int)($this->arParams['SUBJECT_ID'] ?? 0)),
				gridId: $gridId,
				filterId: $this->filter->getId(),
				urlContext: $this->getRowUrlContext(),
			),
		);
	}

	public function executeComponent(): void
	{
		$isCompareMode = (($this->arParams['COMPARE_MODE'] ?? 'N') === 'Y');

		$modeParam = $this->arParams['MODE'] ?? 'project';
		$this->mode = match (true) {
			in_array($modeParam, ['scrum', 'tasks_scrum'], true) => 'scrum',
			$modeParam === 'user_groups' => 'user',
			$modeParam === '' => 'common',
			default => 'project',
		};
		$currentUserId = (int)CurrentUser::get()->getId();
		$contextUserId = (int)($this->arParams['USER_ID'] ?? $currentUserId);
		$taskRealtimeStrategy = $this->getTaskRealtimeStrategy($currentUserId, $contextUserId);

		$idSuffix = $this->isNotCurrentUser() ? '_NOT_CURRENT' : '';
		$customFilterId = (string)($this->arParams['FILTER_ID'] ?? '');
		$extranetSiteId = Container::getInstance()->getExtranetUserService()->getExtranetSiteId();

		$this->filter = match (true) {
			$this->isScrum() => new ScrumFilter(
				currentUserId: $currentUserId,
				idSuffix: $idSuffix,
				extranetSiteId: $extranetSiteId,
				customId: $customFilterId,
			),
			$this->isCommon() => new WorkgroupFilter(
				currentUserId: $currentUserId,
				idSuffix: $idSuffix,
				extranetSiteId: $extranetSiteId,
				customId: $customFilterId,
			),
			$this->isUser() => new UserWorkgroupFilter(
				currentUserId: $currentUserId,
				contextUserId: $contextUserId,
				idSuffix: $idSuffix,
				extranetSiteId: $extranetSiteId,
				customId: $customFilterId,
			),
			default => new ProjectFilter(
				currentUserId: $currentUserId,
				idSuffix: $idSuffix,
				extranetSiteId: $extranetSiteId,
				customId: $customFilterId,
			),
		};
		$this->syncUserFilterPreset($currentUserId, $contextUserId);

		$this->setTitle();
		$this->initPagination();
		$this->initGrid();
		$this->getGrid()->processRequest();

		$this->initProjectParams();

		$isAdmin = CSocNetUser::isCurrentUserModuleAdmin();

		if ($this->isScrum())
		{
			$scrumService = Container::getInstance()->getScrumService();
			$this->totalCount = $scrumService->getCount(
				filter: $this->projectParams->getFilter(),
				currentUserId: $currentUserId,
				isAdmin: $isAdmin,
			);
		}
		elseif ($this->isCommonOrUser())
		{
			$workgroupService = Container::getInstance()->getWorkgroupGridService();
			$this->totalCount = $workgroupService->getCount(
				filter: $this->projectParams->getFilter(),
				currentUserId: $currentUserId,
				isAdmin: $isAdmin,
			);
		}
		else
		{
			$projectService = Container::getInstance()->getProjectService();
			$this->totalCount = $projectService->getCount(
				filter: $this->projectParams->getFilter(),
				currentUserId: $currentUserId,
				isAdmin: $isAdmin,
			);
		}

		$this->pageNavigation->setRecordCount($this->totalCount);

		$this->getGrid()->setRawRows($this->getRows($currentUserId, $isAdmin));

		$this->initResult($currentUserId, $contextUserId, $taskRealtimeStrategy);
		if (!$isCompareMode)
		{
			$this->initToolbar();
		}

		$this->subscribePull($contextUserId, $taskRealtimeStrategy);

		if ($currentUserId > 0 && !$this->isScrum() && (new ProjectAi())->shouldShow($currentUserId))
		{
			Extension::load('socialnetwork.v2.components.popup.new-projects-popup');
		}

		$this->includeComponentTemplate();
	}

	private function subscribePull(int $contextUserId, string $taskRealtimeStrategy): void
	{
		if (
			$contextUserId <= 0
			|| Context::getCurrent()->getRequest()->isAjaxRequest()
			|| !Loader::includeModule('pull')
		)
		{
			return;
		}

		CPullWatch::Add($contextUserId, PullDictionary::PULL_WORKGROUPS_TAG, true);

		if (
			$this->hasTaskRealtime($taskRealtimeStrategy)
			&& Loader::includeModule('tasks')
		)
		{
			CPullWatch::Add(
				$contextUserId,
				\Bitrix\Tasks\Internals\Project\Pull\PullDictionary::PULL_PROJECTS_TAG,
				true,
			);
		}
	}

	private function syncUserFilterPreset(int $currentUserId, int $contextUserId): void
	{
		if (!$this->isUser())
		{
			return;
		}

		$request = Context::getCurrent()->getRequest();
		if ($request->get('grid_id') !== null)
		{
			return;
		}

		$presetId = ($contextUserId === $currentUserId ? 'my' : 'active');
		$preset = $this->filter->getPresets()[$presetId] ?? null;
		if (!is_array($preset) || empty($preset))
		{
			return;
		}

		$this->filter->getOptions()->setFilterSettings($presetId, $preset, true, false);
		$this->filter->getOptions()->save();
	}

	private function getGrid(): Grid
	{
		if (!isset($this->grid))
		{
			$this->initGrid();
		}

		return $this->grid;
	}

	private function getGridSettings(): array
	{
		$settings = [
			'AJAX_MODE' => 'Y',
			'AJAX_OPTION_JUMP' => 'N',
			'AJAX_OPTION_HISTORY' => 'N',
			'NAV_OBJECT' => $this->pageNavigation,
			'NAV_PARAM_NAME' => self::NAV_ID,
			'TOTAL_ROWS_COUNT' => $this->totalCount,
			'SHOW_GRID_SETTINGS_MENU' => true,
			'SHOW_CHECK_ALL_CHECKBOXES' => true,
			'SHOW_ROW_CHECKBOXES' => true,
			'SHOW_ROW_ACTIONS_MENU' => true,
			'SHOW_SELECTED_COUNTER' => true,
			'SHOW_MORE_BUTTON' => true,
			'SHOW_PAGINATION' => true,
			'SHOW_TOTAL_COUNTER' => true,
			'ALLOW_COLUMNS_SORT' => true,
			'ALLOW_HORIZONTAL_SCROLL' => true,
			'ALLOW_SORT' => true,
			'HANDLE_RESPONSE_ERRORS' => true,
			'USE_CHECKBOX_LIST_FOR_SETTINGS_POPUP' => true,
			'SHOW_ACTION_PANEL' => false,
			'TOP_ACTION_PANEL_RENDER_TO' => '.page__toolbar,.task-interface-toolbar,.ui-side-panel-toolbar',
			'TOP_ACTION_PANEL_PINNED_MODE' => false,
			'ACTION_PANEL_OPTIONS' => ['MAX_HEIGHT' => 58],
		];

		if ($this->totalCount === 0)
		{
			$settings['STUB'] = $this->getStubHtml();
		}

		return $settings;
	}

	private function initPagination(): void
	{
		$gridOptions = new Options($this->getGridId());
		$navParams = $gridOptions->getNavParams(['nPageSize' => self::PAGE_SIZE]);
		$pageSize = (int)$navParams['nPageSize'];

		$this->pageNavigation = new PageNavigation(self::NAV_ID);
		$this->pageNavigation
			->allowAllRecords(false)
			->setPageSize($pageSize)
			->initFromUri()
		;
	}

	private function initGrid(): void
	{
		if (!empty($this->grid))
		{
			return;
		}

		$settings = new Settings([
			'ID' => $this->getGridId(),
			'FILTER_ID' => $this->filter->getId(),
			'PRESETS' => $this->filter->getPresets(),
		]);
		$currentUserId = (int)CurrentUser::get()->getId();

		$contextUserId = (int)($this->arParams['USER_ID'] ?? $currentUserId);

		$this->grid = match (true) {
				$this->isScrum() => new ScrumGrid(
					settings: $settings,
					currentUserId: $currentUserId,
					contextUserId: $contextUserId,
					filterId: $this->filter->getId(),
				),
				$this->isCommonOrUser() => new WorkgroupGrid(
					settings: $settings,
					currentUserId: $currentUserId,
					isCommonMode: $this->isCommon(),
					contextUserId: $contextUserId,
					filterId: $this->filter->getId(),
				),
				default => new ProjectGrid(
					settings: $settings,
					currentUserId: $currentUserId,
					isProjectMode: !$this->isScrum(),
					contextUserId: $contextUserId,
					filterId: $this->filter->getId(),
				),
			};
	}

	private function getGridId(): string
	{
		$customGridId = (string)($this->arParams['GRID_ID'] ?? '');
		if ($customGridId !== '')
		{
			return $customGridId;
		}

		$id = match (true) {
			$this->isScrum() => 'SONET_GROUP_LIST_SCRUM',
			$this->isCommon() => 'SONET_GROUP_LIST',
			$this->isUser() => 'SONET_GROUP_LIST_USER',
			default => 'SONET_GROUP_LIST_PROJECT',
		};

		if ($this->isNotCurrentUser())
		{
			$id .= '_NOT_CURRENT';
		}

		return $id;
	}

	private function isNotCurrentUser(): bool
	{
		$contextUserId = (int)($this->arParams['USER_ID'] ?? 0);

		return $contextUserId > 0 && $contextUserId !== (int)CurrentUser::get()->getId();
	}

	private function initProjectParams(): void
	{
		$pager = new Pager(
			limit: $this->pageNavigation->getLimit(),
			offset: $this->pageNavigation->getOffset(),
		);

		$gridFilter = $this->getGrid()->getFilter();
		$filterFields = $gridFilter !== null ? $gridFilter->getFieldArrays() : [];
		$currentUserId = (int)(CurrentUser::get()->getId() ?? 0);

		$subjectId = (int)($this->arParams['SUBJECT_ID'] ?? 0);
		$filter = $this->createGridFilter($filterFields, $currentUserId, $subjectId);

		$visibleColumns = $this->getGrid()->getVisibleColumnsIds();
		$shouldLoadMembers = (
			in_array('MEMBERS', $visibleColumns, true)
			|| in_array('ROLE', $visibleColumns, true)
		);
		$select = new ProjectSelect(
			select: $this->getSelectFieldsFromGrid($visibleColumns),
			members: $shouldLoadMembers,
			owner: in_array('OWNER', $visibleColumns, true),
		);

		$gridSort = $this->getGrid()->getOrmOrder();
		$sort = new ProjectSort($this->mapGridSortToFields($gridSort));

		$this->projectParams = new ProjectParams(
			pager: $pager,
			filter: $filter,
			sort: $sort,
			select: $select,
		);
	}

	private function createGridFilter(array $filterFields, int $currentUserId, int $subjectId): AbstractProjectFilter
	{
		$container = Container::getInstance();
		$filterOptions = $this->filter->getOptions();
		$workgroupFilterRepository = $container->getWorkgroupFilterRepository();
		$favoritesRepository = $container->getFavoritesRepository();

		if ($this->isScrum())
		{
			return new ScrumGridFilter(
				filterOptions: $filterOptions,
				filterFields: $filterFields,
				workgroupFilterRepository: $workgroupFilterRepository,
				favoritesRepository: $favoritesRepository,
				projectCounterFilterService: $container->getProjectCounterFilterService(),
				currentUserId: $currentUserId,
			);
		}

		if ($this->isCommonOrUser())
		{
			return new WorkgroupGridFilter(
				filterOptions: $filterOptions,
				filterFields: $filterFields,
				workgroupFilterRepository: $workgroupFilterRepository,
				favoritesRepository: $favoritesRepository,
				workgroupCounterRepository: $container->getWorkgroupCounterRepository(),
				currentUserId: $currentUserId,
			);
		}

		return new ProjectGridFilter(
			filterOptions: $filterOptions,
			filterFields: $filterFields,
			workgroupFilterRepository: $workgroupFilterRepository,
			favoritesRepository: $favoritesRepository,
			projectCounterFilterService: $container->getProjectCounterFilterService(),
			currentUserId: $currentUserId,
			subjectId: $subjectId,
		);
	}

	private function getSelectFieldsFromGrid(array $visibleColumns): array
	{
		$fields = ['id', 'closed', 'opened', 'visible', 'type', 'scrumMasterId'];

		foreach ($visibleColumns as $columnId)
		{
			if (isset(self::GRID_COLUMN_TO_FIELD[$columnId]))
			{
				$fields[] = self::GRID_COLUMN_TO_FIELD[$columnId];
			}
		}

		if (in_array('NAME', $visibleColumns, true))
		{
			$fields[] = 'imageId';
		}

		if (in_array('MEMBERS', $visibleColumns, true))
		{
			$fields[] = 'numberOfMembers';
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

	private function getRows(int $currentUserId = 0, bool $isAdmin = false): array
	{
		if ($this->rows !== null)
		{
			return $this->rows;
		}

		if ($this->isScrum())
		{
			$this->rows = $this->getScrumRows($currentUserId, $isAdmin);
			$this->fillViewUrlData();
			$this->fillActionUrlData();
			return $this->rows;
		}
		elseif ($this->isCommonOrUser())
		{
			$this->rows = $this->getCommonProjectRows($currentUserId, $isAdmin);
		}
		else
		{
			$this->rows = $this->getProjectRows($currentUserId, $isAdmin);
			$this->fillViewUrlData();
			$this->fillActionUrlData();
			return $this->rows;
		}

		$this->fillAccessData($currentUserId);
		$this->fillPinData($currentUserId);
		$this->fillFavoriteData($currentUserId);
		$this->fillChatData();
		$this->fillViewUrlData();
		$this->fillActionUrlData();

		return $this->rows;
	}

	private function getPinUserIdForCurrentContext(int $currentUserId): ?int
	{
		if ($currentUserId <= 0)
		{
			return null;
		}

		$contextUserId = (int)($this->arParams['USER_ID'] ?? $currentUserId);

		return ($contextUserId === $currentUserId ? $currentUserId : null);
	}

	private function getProjectRows(int $currentUserId, bool $isAdmin): array
	{
		$visibleColumns = $this->getGrid()->getVisibleColumnsIds();

		return Container::getInstance()->getProjectListGridRowService()->loadProjectRows(
			filter: $this->projectParams->getFilter(),
			sort: $this->projectParams->getSort() ?? [],
			offset: $this->projectParams->getOffset(),
			limit: $this->projectParams->getLimit(),
			select: $this->projectParams->getSelect() ?? ['*'],
			withImage: true,
			withMembers: $this->projectParams->select?->members ?? false,
			withOwner: $this->projectParams->select?->owner ?? false,
			withTags: in_array('TAGS', $visibleColumns, true),
			withRelationDate: in_array('DATE_RELATION', $visibleColumns, true),
			withViewDate: in_array('DATE_VIEW', $visibleColumns, true),
			currentUserId: $currentUserId,
			contextUserId: (int)($this->arParams['USER_ID'] ?? $currentUserId),
			isAdmin: $isAdmin,
			pinMode: $this->getInternalGridPinMode(),
		);
	}

	private function getCommonProjectRows(int $currentUserId, bool $isAdmin): array
	{
		$workgroupService = Container::getInstance()->getWorkgroupGridService();
		$visibleColumns = $this->getGrid()->getVisibleColumnsIds();

		$gridRows = $workgroupService->getGridList(
			filter: $this->projectParams->getFilter(),
			sort: $this->projectParams->getSort() ?? [],
			offset: $this->projectParams->getOffset(),
			limit: $this->projectParams->getLimit(),
			select: $this->projectParams->getSelect() ?? ['*'],
			withImage: true,
			withMembers: $this->projectParams->select?->members ?? false,
			withTags: in_array('TAGS', $visibleColumns, true),
			withRelationDate: in_array('DATE_RELATION', $visibleColumns, true),
			withViewDate: in_array('DATE_VIEW', $visibleColumns, true),
			currentUserId: $currentUserId,
			contextUserId: (int)($this->arParams['USER_ID'] ?? $currentUserId),
			isAdmin: $isAdmin,
			pinUserId: $this->getPinUserIdForCurrentContext($currentUserId),
			pinMode: $this->getInternalGridPinMode(),
		);

		$rows = [];
		foreach ($gridRows as $gridRow)
		{
			$rows[] = $gridRow->toArray();
		}

		return $rows;
	}

	private function getScrumRows(int $currentUserId, bool $isAdmin): array
	{
		return Container::getInstance()->getProjectListGridRowService()->loadScrumRows(
			filter: $this->projectParams->getFilter(),
			sort: $this->projectParams->getSort() ?? [],
			offset: $this->projectParams->getOffset(),
			limit: $this->projectParams->getLimit(),
			select: $this->projectParams->getSelect() ?? ['*'],
			withImage: true,
			withMembers: $this->projectParams->select?->members ?? false,
			withOwner: $this->projectParams->select?->owner ?? false,
			currentUserId: $currentUserId,
			contextUserId: (int)($this->arParams['USER_ID'] ?? $currentUserId),
			isAdmin: $isAdmin,
			pinMode: $this->getInternalGridPinMode(),
		);
	}

	private function fillEfficiency(array &$rows): void
	{
		$projectIds = array_column($rows, 'ID');
		if (empty($projectIds))
		{
			return;
		}

		$efficiencies = (new ProjectEfficiencyService())->getEfficiency($projectIds);

		foreach ($rows as &$row)
		{
			$projectId = (int)$row['ID'];
			$row['EFFICIENCY'] = $efficiencies[$projectId] ?? 0;
		}
		unset($row);
	}

	private function fillAccessData(int $currentUserId): void
	{
		if ($currentUserId <= 0)
		{
			return;
		}

		$contextUserId = (int)($this->arParams['USER_ID'] ?? $currentUserId);

		$this->fillAccessDataByService($currentUserId, $contextUserId);
	}

	private function fillAccessDataByService(int $currentUserId, int $contextUserId): void
	{
		$ids = array_column($this->rows, 'ID');
		if (empty($ids))
		{
			return;
		}

		$accessService = $this->isScrum()
			? Container::getInstance()->getScrumAccessService()
			: Container::getInstance()->getLegacyGroupAccessService();

		$memberRepository = Container::getInstance()->getProjectMemberRepository();
		$currentUserRelations = $memberRepository->getUserRelations(
			groupIds: $ids,
			userId: $currentUserId,
		);
		$displayRelations = ($contextUserId > 0 && $contextUserId !== $currentUserId)
			? $memberRepository->getUserRelations(
				groupIds: $ids,
				userId: $contextUserId,
			)
			: $currentUserRelations
		;

		$showCurrentUserRoleActions = ($contextUserId === $currentUserId);

		foreach ($this->rows as &$row)
		{
			$id = (int)$row['ID'];
			$currentUserRelation = $currentUserRelations[$id] ?? null;
			$displayRelation = $displayRelations[$id] ?? null;
			$roleRelation = $showCurrentUserRoleActions
				? $currentUserRelation
				: (
					$displayRelation instanceof WorkgroupUserRelation && $displayRelation->isMember()
						? $displayRelation
						: null
				)
			;

			$row['CURRENT_USER_RELATION'] = $currentUserRelation;
			$row['ROLE_RELATION'] = $roleRelation;
			$row['SHOW_ROLE_ACTIONS'] = $showCurrentUserRoleActions;
			$row['CAN_MODIFY'] = $accessService->canUpdate($currentUserId, $id);
			$row['CAN_DELETE'] = $accessService->canDelete($currentUserId, $id);
			$row['CAN_LEAVE'] = $accessService->canLeave($currentUserId, $id);
			$row['CAN_JOIN'] = $accessService->canJoin($currentUserId, $id);
			$row['CAN_DELETE_INCOMING_REQUEST'] = Access::canDeleteIncomingRequest([
				'groupId' => $id,
				'userId' => $currentUserId,
			]);
			$row['CAN_DELETE_OUTGOING_REQUEST'] = Access::canDeleteOutgoingRequest([
				'groupId' => $id,
				'userId' => $currentUserId,
			]);
		}
		unset($row);
	}

	private function fillPinData(int $currentUserId): void
	{
		$ids = array_column($this->rows, 'ID');
		if (empty($ids) || $currentUserId <= 0)
		{
			return;
		}

		$pinnedIds = Container::getInstance()
			->getWorkgroupPinService()
			->getPinFlags(
				groupIds: $ids,
				userId: $currentUserId,
				mode: $this->getInternalGridPinMode(),
			);

		foreach ($this->rows as &$row)
		{
			$id = (int)$row['ID'];
			$row['IS_PINNED'] = isset($pinnedIds[$id]);
		}
		unset($row);
	}

	private function getGridPinMode(): PinMode
	{
		return PinMode::fromMode($this->mode);
	}

	private function getInternalGridPinMode(): WorkgroupPinMode
	{
		return $this->getPinModeMapper()->mapToInternal($this->getGridPinMode());
	}

	private function getPinModeMapper(): PinModeMapper
	{
		return Container::getInstance()->getPinModeMapper();
	}

	private function fillFavoriteData(int $currentUserId): void
	{
		$ids = array_column($this->rows, 'ID');
		if (empty($ids) || $currentUserId <= 0)
		{
			return;
		}

		$favoriteIds = Container::getInstance()
			->getFavoritesRepository()
			->getFavoriteFlags(groupIds: $ids, userId: $currentUserId);

		foreach ($this->rows as &$row)
		{
			$id = (int)$row['ID'];
			$row['IS_FAVORITE'] = isset($favoriteIds[$id]);
		}
		unset($row);
	}

	private function fillChatData(): void
	{
		$ids = array_values(array_filter(array_column($this->rows, 'ID')));
		if ($ids === [])
		{
			return;
		}

		$projectChatData = Container::getInstance()
			->getProjectChatDataProvider()
			->getByProjectIds($ids);

		foreach ($this->rows as &$row)
		{
			$id = (int)($row['ID'] ?? 0);
			$chatData = $projectChatData[$id] ?? [
				'chatId' => null,
				'color' => null,
			];
			$row['CHAT_ID'] = $chatData['chatId'] ?? null;
			$row['COLOR'] = $chatData['color'] ?? null;
		}
		unset($row);
	}

	private function fillViewUrlData(): void
	{
		if (empty($this->rows))
		{
			return;
		}

		$provider = $this->getViewUrlProvider();
		foreach ($this->rows as &$row)
		{
			$row['VIEW_URL'] = $provider->getUrl(
				groupId: (int)($row['ID'] ?? 0),
				groupType: $this->resolveRowType($row),
			);
		}
		unset($row);
	}

	private function fillActionUrlData(): void
	{
		if (empty($this->rows))
		{
			return;
		}

		$provider = $this->getActionUrlProvider();
		foreach ($this->rows as &$row)
		{
			$id = (int)($row['ID'] ?? 0);
			$row['EDIT_URL'] = $provider->getEditUrl($id);
			$row['DELETE_URL'] = $provider->getDeleteUrl($id);
			$row['LEAVE_URL'] = $provider->getLeaveUrl($id);
		}
		unset($row);
	}

	private function getActionUrlProvider(): WorkgroupActionUrlProvider
	{
		if ($this->actionUrlProvider === null)
		{
			$this->actionUrlProvider = new WorkgroupActionUrlProvider(
				pathToGroupEdit: (string)($this->arParams['PATH_TO_GROUP_EDIT'] ?? ''),
				pathToGroupDelete: (string)($this->arParams['PATH_TO_GROUP_DELETE'] ?? ''),
				pathToLeaveGroup: (string)($this->arParams['PATH_TO_LEAVE_GROUP'] ?? ''),
			);
		}

		return $this->actionUrlProvider;
	}

	private function getViewUrlProvider(): WorkgroupViewUrlProvider
	{
		if ($this->viewUrlProvider === null)
		{
			$this->viewUrlProvider = new WorkgroupViewUrlProvider(
				mode: (string)($this->arParams['MODE'] ?? ''),
				pathToGroup: (string)($this->arParams['PATH_TO_GROUP'] ?? ''),
				pathToGroupTasks: (string)($this->arParams['PATH_TO_GROUP_TASKS'] ?? ''),
			);
		}

		return $this->viewUrlProvider;
	}

	private function resolveRowType(array $row): ?string
	{
		$type = $row['TYPE'] ?? null;
		if (is_string($type) && $type !== '')
		{
			return $type;
		}

		return $this->isScrum()
			? Type::Scrum->value
			: null;
	}

	private function initResult(int $currentUserId, int $contextUserId, string $taskRealtimeStrategy): void
	{
		$grid = ComponentParams::get($this->getGrid(), $this->getGridSettings());
		$gridId = (string)($grid['GRID_ID'] ?? $this->getGridId());

		$mode = $this->arParams['MODE'] ?? '';
		$hasAccessToCounters = TasksCounter::getAccessToTasksCounters([
			'mode' => $mode,
			'contextUserId' => (int)($this->arParams['USER_ID'] ?? 0),
		]);

		$this->arResult = [
			'GRID' => $grid,
			'GRID_ID' => $gridId,
			'TOURS' => $this->arParams['TOURS'] ?? [],
			'ACTION_PREFIX' => $this->getActionPrefix(),
			'ENTITY_PARAM' => $this->getEntityParam(),
			'TASK_REALTIME_ACTIONS' => $this->getTaskRealtimeActions(),
			'PIN_MODE' => $this->getGridPinMode()->value,
			'USES_TASKS_PULL' => $this->usesLegacyTasksPull($taskRealtimeStrategy),
			'TASK_REALTIME_STRATEGY' => $taskRealtimeStrategy,
			'REALTIME_CAPABILITIES' => $this->getRealtimeCapabilities($taskRealtimeStrategy),
			'SIGNED_PAGE_CONTEXT' => $this->getSignedPageContext($contextUserId, $taskRealtimeStrategy, $gridId),
			'REALTIME_UI_CONTEXT' => $this->getRealtimeUiContext(),
			'FILTER_ID' => $this->filter->getId(),
			'CURRENT_COUNTER' => '',
			'TASKS_COUNTERS' => $hasAccessToCounters
				? TasksCounter::getTasksCounters(['mode' => $mode])
				: [],
			'TASKS_COUNTERS_SCOPE' => $hasAccessToCounters
				? TasksCounter::getTasksCountersScope(['mode' => $mode])
				: '',
		];
	}

	private function initToolbar(): void
	{
		$this->initToolbarFilter();
		$this->initToolbarCreateButton();
	}

	private function initToolbarCreateButton(): void
	{
		$currentUserId = (int)CurrentUser::get()->getId();
		if ($currentUserId <= 0 || !Access::canCreate())
		{
			return;
		}

		$pathToCreate = $this->arParams['PATH_TO_GROUP_CREATE'] ?? '';
		if ($pathToCreate === '')
		{
			$pathToCreate = '/company/personal/user/#user_id#/groups/create/';
		}

		$url = str_replace(
			['#id#', '#ID#', '#USER_ID#', '#user_id#'],
			(string)$currentUserId,
			$pathToCreate,
		);

		$text = $this->isScrum()
			? Loc::getMessage('SOCIALNETWORK_PROJECT_LIST_CREATE_SCRUM') ?? ''
			: Loc::getMessage('SOCIALNETWORK_PROJECT_LIST_CREATE_PROJECT') ?? ''
		;

		if ($this->isScrum())
		{
			$uri = new Uri($url);
			$uri->addParams(['PROJECT_OPTIONS' => ['scrum' => true]]);
			$url = $uri->getUri();

			$buttonOptions = [
				'color' => Color::SUCCESS,
				'text' => $text,
			];

			$isRestricted = !Feature::isFeatureEnabled(Feature::SCRUM_CREATE);

			if ($isRestricted)
			{
				$buttonOptions['click'] = new JsCode("BX.UI.FeaturePromotersRegistry.getPromoter({ featureId: 'socialnetwork_scrum_create' }).show();");
			}
			else
			{
				$buttonOptions['link'] = $url;
			}
		}
		else
		{
			$buttonOptions = [
				'color' => Color::SUCCESS,
				'text' => $text,
			];

			$isRestricted = !Feature::isFeatureEnabled(Feature::PROJECTS_GROUPS) && !Feature::canTurnOnTrial(Feature::PROJECTS_GROUPS);

			if ($isRestricted)
			{
				$buttonOptions['click'] = new JsCode("BX.UI.FeaturePromotersRegistry.getPromoter({ featureId: 'socialnetwork_projects_groups' }).show();");
			}
			else if (\Bitrix\Socialnetwork\V2\Feature::isNewProjectsOn())
			{
				$buttonOptions['click'] = new JsCode('BX.Messenger.Public.openChatCreation("collab");');
			}
			else
			{
				$buttonOptions['link'] = $url;
			}
		}

		$button = new Button($buttonOptions);
		$button->addAttribute('id', 'projectAddButton');

		Toolbar::addButton($button, ButtonLocation::AFTER_TITLE);
	}

	private function initToolbarFilter(): void
	{
		$options = $this->getToolbarFilterOptions();

		Toolbar::addFilter($options);
	}

	protected function getToolbarFilterOptions(): array
	{
		return Filter\Component\ComponentParams::get(
			$this->getGrid()->getFilter(),
			[
				'GRID_ID' => $this->getGridId(),
				'FILTER_ID' => $this->filter->getId(),
				'FILTER_PRESETS' => $this->filter->getPresets(),
				'RESET_TO_DEFAULT_MODE' => true,
				'DISABLE_SEARCH' => false,
				'ENABLE_LIVE_SEARCH' => false,
				'THEME' => Theme::MUTED,
			],
		);
	}

	private function getStubHtml(): string
	{
		if ($this->isScrum())
		{
			return $this->getScrumStubHtml();
		}

		$stubTitle = Loc::getMessage('SOCIALNETWORK_PROJECT_LIST_STUB_TITLE');
		$stubSubtitle = Loc::getMessage('SOCIALNETWORK_PROJECT_LIST_STUB_SUBTITLE');

		return <<<HTML
			<div class="socialnetwork-project-list-stub">
				<div class="socialnetwork-project-list-stub-img"></div>
				<div class="ui-headline --md socialnetwork-project-list-stub-title">{$stubTitle}</div>
				<div class="ui-text --lg socialnetwork-project-list-stub-subtitle">{$stubSubtitle}</div>
			</div>
HTML;
	}

	private function getScrumStubHtml(): string
	{
		$title = Loc::getMessage('SOCIALNETWORK_SCRUM_LIST_STUB_TITLE');
		$description = Loc::getMessage('SOCIALNETWORK_SCRUM_LIST_STUB_DESCRIPTION');
		$migrationTitle = Loc::getMessage('SOCIALNETWORK_SCRUM_LIST_STUB_MIGRATION_TITLE');
		$migrationButton = Loc::getMessage('SOCIALNETWORK_SCRUM_LIST_STUB_MIGRATION_BUTTON');
		$migrationOther = Loc::getMessage('SOCIALNETWORK_SCRUM_LIST_STUB_MIGRATION_OTHER');

		$templateFolder = '/bitrix/components/bitrix/socialnetwork.group.list/templates/.default';
		$jiraIcon = $templateFolder . '/images/tasks-projects-jira.svg';
		$asanaIcon = $templateFolder . '/images/tasks-projects-asana.svg';
		$trelloIcon = $templateFolder . '/images/tasks-projects-trello.svg';

		return <<<HTML
			<div class="sg-tasks-scrum__transfer--contant">
				<div class="sg-tasks-scrum__transfer--title">{$title}</div>
				<div class="sg-tasks-scrum__transfer--description">{$description}</div>
				<div class="sg-tasks-scrum__transfer--content">
					<div class="sg-tasks-scrum__transfer--info">
						<div class="sg-tasks-scrum__transfer--info-text">{$migrationTitle}</div>
						<div class="sg-tasks-scrum__transfer--info-systems">
							<div class="sg-tasks-scrum__transfer--info-systems-item">
								<img src="{$jiraIcon}" alt="Jira">
							</div>
							<div class="sg-tasks-scrum__transfer--info-systems-item">
								<img src="{$asanaIcon}" alt="Asana">
							</div>
							<div class="sg-tasks-scrum__transfer--info-systems-item">
								<img src="{$trelloIcon}" alt="Trello">
							</div>
							<div class="sg-tasks-scrum__transfer--info-systems-item">{$migrationOther}</div>
						</div>
					</div>
					<a href="/market/collection/scrum_migration/" class="ui-btn ui-btn-primary ui-btn-round">{$migrationButton}</a>
				</div>
			</div>
HTML;
	}

	private function setTitle(): void
	{
		if (($this->arParams['SET_TITLE'] ?? 'Y') === 'N')
		{
			return;
		}

		global $APPLICATION;

		$subjectId = (int)($this->arParams['SUBJECT_ID'] ?? 0);
		$page = $this->arParams['PAGE'] ?? '';

		if ($page === 'groups_subject' && $subjectId > 0)
		{
			$title = Subject::getName($subjectId);
		}
		else
		{
			$key = $this->isScrum()
				? 'SOCIALNETWORK_SCRUM_LIST_TITLE'
				: 'SOCIALNETWORK_PROJECT_LIST_TITLE';
			$title = Loc::getMessage($key);
		}

		$APPLICATION->setTitle($title);

		if (($this->arParams['SET_NAV_CHAIN'] ?? 'Y') !== 'N')
		{
			$APPLICATION->AddChainItem($title);
		}
	}
}
