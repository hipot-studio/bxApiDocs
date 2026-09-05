<?php

use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Mail\Dto\SharedSignatureDto;
use Bitrix\Mail\Integration\UI\EntitySelector\MailboxProvider;
use Bitrix\Mail\Internals\SharedSignatureAssignmentTable;
use Bitrix\Mail\Internals\SharedSignatureTable;
use Bitrix\Mail\Service\SharedSignature\AssignmentResolver;
use Bitrix\Mail\Service\SharedSignature\AssignmentTargetDirectory;
use Bitrix\Mail\Service\SharedSignature\SharedSignatureService;
use Bitrix\Mail\Service\SharedSignature\SignatureMigrator;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) die();

class MailUserSignatureListComponent extends CBitrixComponent
{
	protected $gridId = 'mail-usersignature-grid';
	protected $filterId = 'mail-usersignature-filter';
	protected $navParamName = 'page';

	/* Entities of the assignment selector. The editor assigns a signature through the same ones. */
	protected const SELECTOR_ENTITY_USER = 'user';
	protected const SELECTOR_ENTITY_DEPARTMENT = 'department';

	/** @var array<string, string> selector entity → type of an assignment target */
	protected const TARGET_TYPE_BY_SELECTOR_ENTITY = [
		MailboxProvider::PROVIDER_ENTITY_ID => SharedSignatureAssignmentTable::TARGET_MAILBOX,
		self::SELECTOR_ENTITY_USER => SharedSignatureAssignmentTable::TARGET_USER,
		self::SELECTOR_ENTITY_DEPARTMENT => SharedSignatureAssignmentTable::TARGET_DEPARTMENT,
	];

	protected ?SharedSignatureService $signatureService = null;
	protected ?AssignmentTargetDirectory $targetDirectory = null;
	protected ?bool $sharedScopeManageable = null;
	protected ?array $requestFilter = null;
	protected ?array $listVisibilityFilter = null;
	protected ?array $visibleSignatureIds = null;

	/**
	 * @param $arParams
	 * @return array
	 */
	public function onPrepareComponentParams($arParams)
	{
		$arParams = parent::onPrepareComponentParams($arParams);

		// A signature of the owner scope is visible to its owner alone, so whose list to show
		// is not up to the caller: the parameter is pinned to the current user.
		$arParams['USER_ID'] = (int)\Bitrix\Main\Engine\CurrentUser::get()->getId();

		return $arParams;
	}

	/**
	 * @return mixed|void
	 */
	public function executeComponent()
	{
		if(!Loader::includeModule('mail'))
		{
			$this->showError(Loc::getMessage('MAIL_USERSIGNATURE_MODULE_ERROR'));
			return;
		}

		// Opening the list migrates the signatures of its owner first (P7.T2): the grid must show
		// all of them, and the read below answers from the unified model as soon as a single row of
		// this user is there.
		$this->migrateOwnSignatures();

		$this->arResult = [];

		$this->arResult['addUrl'] = new \Bitrix\Main\Web\Uri(\CComponentEngine::makePathFromTemplate($this->arParams['PATH_TO_MAIL_SIGNATURE'], ['id' => "0"]));
		$this->arResult['IFRAME'] = $this->arParams['IFRAME'] == 'Y' || $this->request->get('IFRAME') == 'Y' ? 'Y' : 'N';
		$this->arResult['FILTER'] = $this->prepareFilter();
		$this->arResult['GRID'] = $this->prepareGrid();
		$this->arResult['TITLE'] = Loc::getMessage('MAIL_USERSIGNATURE_LIST_TITLE');

		global $APPLICATION;
		$APPLICATION->SetTitle($this->arResult['TITLE']);

		$this->includeComponentTemplate();
	}

	protected function showError($error)
	{
		ShowError($error);
		$this->includeComponentTemplate();
	}

	/**
	 * Copies the signatures of the current user into the unified model, synchronously and before
	 * the list is read. Bounded by his own rows: the list cannot show anybody else's personal
	 * signature, so there is nothing else to migrate here.
	 */
	protected function migrateOwnSignatures(): void
	{
		(new SignatureMigrator())->migrateUser((int)$this->arParams['USER_ID']);
	}

	/**
	 * @return array
	 */
	protected function prepareGrid()
	{
		$grid = [];
		$grid["ROWS"] = [];
		$grid['GRID_ID'] = $this->gridId;
		$grid['COLUMNS'] = $this->getGridColumns();

		$gridOptions = new Bitrix\Main\Grid\Options($this->gridId);
		$navParams = $gridOptions->getNavParams(['nPageSize' => 10]);
		$pageSize = (int)$navParams['nPageSize'];
		$pageNavigation = new \Bitrix\Main\UI\PageNavigation($this->navParamName);
		$pageNavigation->allowAllRecords(false)->setPageSize($pageSize)->initFromUri();

		// The single source of the screen: the unified model through the domain service. Reading the
		// previous table here would leave the filter answering by half of the rows.
		$service = $this->getSignatureService();
		$filter = $this->getListFilter();

		$fullCount = $service->getTotalCount($filter);
		$entries = $service->getList($pageNavigation->getLimit(), $pageNavigation->getOffset(), $filter);

		$withServiceColumns = $this->areServiceColumnsVisible();

		// One resolver and one directory per page: their caches are shared by all rows
		$resolver = new AssignmentResolver();
		$directory = $this->getTargetDirectory();

		$assignmentsBySignature = [];
		foreach ($entries as $entry)
		{
			$assignmentsBySignature[(int)$entry['signature']->getId()] = $entry['assignments'];
		}
		$targetTitles = $directory->getTitlesBySignature($assignmentsBySignature);
		$authorNames = $withServiceColumns ? $this->getAuthorNames($entries) : [];

		foreach ($entries as $entry)
		{
			$dto = SharedSignatureDto::fromRows(
				$entry['signature']->collectValues(),
				$entry['assignments'],
				$resolver
			);

			$columns = [
				'ID' => $dto['id'],
				'SIGNATURE' => htmlspecialcharsbx(mb_substr(strip_tags($dto['signature']), 0, 100), ENT_COMPAT, false),
				'ASSIGNED_TO' => $this->formatAssignedTo($dto['scope'], $targetTitles[$dto['id']] ?? []),
			];

			if ($withServiceColumns)
			{
				$columns['TYPE'] = $this->renderScopeLabel($dto['scope']);
				$columns['CREATED_BY'] = htmlspecialcharsbx((string)($authorNames[$dto['createdBy']] ?? ''));
				$columns['DATE_MODIFY'] = htmlspecialcharsbx($dto['dateModify']);
			}

			$grid['ROWS'][] = [
				'id' => $dto['id'],
				'data' => $dto,
				'columns' => $columns,
				'actions' => $this->getRowActions($dto),
			];
		}

		$pageNavigation->setRecordCount($fullCount);
		$grid['TOTAL_ROWS_COUNT'] = $fullCount;
		$grid['NAV_OBJECT'] = $pageNavigation;
		$grid['AJAX_MODE'] = 'Y';
		$grid['ALLOW_ROWS_SORT'] = false;
		$grid['AJAX_OPTION_JUMP'] = "N";
		$grid['AJAX_OPTION_STYLE'] = "N";
		$grid['AJAX_OPTION_HISTORY'] = "N";
		$grid['SHOW_PAGESIZE'] = false;
		$grid['AJAX_ID'] = \CAjax::GetComponentID("bitrix:main.ui.grid", '', '');
		$grid['SHOW_ROW_CHECKBOXES'] = false;
		$grid['SHOW_CHECK_ALL_CHECKBOXES'] = false;
		$grid['SHOW_ACTION_PANEL'] = false;

		return $grid;
	}

	/**
	 * Two columns for everybody — the text of the signature and whom it is assigned to — plus the
	 * type, the author and the date of the last change for whoever manages shared signatures.
	 *
	 * @return array
	 */
	protected function getGridColumns(): array
	{
		$columns = [
			[
				'id' => 'ID',
				'name' => 'ID',
				'default' => false,
			],
			[
				'id' => 'SIGNATURE',
				'name' => Loc::getMessage('MAIL_USERSIGNATURE_LIST_SIGNATURE'),
				'default' => true,
			],
			[
				'id' => 'ASSIGNED_TO',
				'name' => Loc::getMessage('MAIL_USERSIGNATURE_LIST_ASSIGNED_TO'),
				'default' => true,
			],
		];

		if (!$this->areServiceColumnsVisible())
		{
			return $columns;
		}

		$columns[] = [
			'id' => 'TYPE',
			'name' => Loc::getMessage('MAIL_USERSIGNATURE_LIST_TYPE'),
			'default' => true,
		];
		$columns[] = [
			'id' => 'CREATED_BY',
			'name' => Loc::getMessage('MAIL_USERSIGNATURE_LIST_AUTHOR'),
			'default' => true,
		];
		$columns[] = [
			'id' => 'DATE_MODIFY',
			'name' => Loc::getMessage('MAIL_USERSIGNATURE_LIST_DATE_MODIFY'),
			'default' => true,
		];

		return $columns;
	}

	/**
	 * Whom the signature is assigned to. A personal signature names its sender or says it serves
	 * every one of them; a shared signature names its targets.
	 *
	 * @param string[] $targetTitles
	 */
	protected function formatAssignedTo(string $scope, array $targetTitles): string
	{
		if (!empty($targetTitles))
		{
			return htmlspecialcharsbx(implode(', ', $targetTitles));
		}

		return $scope === SharedSignatureTable::SCOPE_OWNER
			? htmlspecialcharsbx((string)Loc::getMessage('MAIL_USERSIGNATURE_LIST_DEFAULT'))
			: htmlspecialcharsbx((string)Loc::getMessage('MAIL_USERSIGNATURE_LIST_ASSIGNED_TO_NOBODY'))
		;
	}

	/**
	 * The type of the signature as an oval label of the design system, the way the neighbouring
	 * grids build it in a cell.
	 */
	protected function renderScopeLabel(string $scope): string
	{
		$isShared = $scope === SharedSignatureTable::SCOPE_SHARED;

		$text = $isShared
			? Loc::getMessage('MAIL_USERSIGNATURE_LIST_TYPE_SHARED')
			: Loc::getMessage('MAIL_USERSIGNATURE_LIST_TYPE_OWN')
		;
		$colorClass = $isShared ? 'ui-label-fill ui-label-lightblue' : 'ui-label-light';

		return sprintf(
			'<span class="ui-label ui-label-sm %s"><span class="ui-label-inner">%s</span></span>',
			$colorClass,
			htmlspecialcharsbx((string)$text)
		);
	}

	/**
	 * Actions of a row. A shared signature assigned to a plain employee is his to read: managing
	 * shared signatures is a right, and without it the row carries no action at all.
	 *
	 * @param array $dto
	 * @return array
	 */
	protected function getRowActions(array $dto): array
	{
		if ($dto['scope'] === SharedSignatureTable::SCOPE_OWNER)
		{
			return $dto['ownerId'] === (int)$this->arParams['USER_ID']
				? $this->buildOwnActions((int)$dto['id'])
				: []
			;
		}

		return $this->canManageSharedScope() ? $this->buildSharedActions($dto) : [];
	}

	/**
	 * @return array
	 */
	protected function buildOwnActions(int $signatureId): array
	{
		$editUrl = new \Bitrix\Main\Web\Uri(
			\CComponentEngine::makePathFromTemplate($this->arParams['PATH_TO_MAIL_SIGNATURE'], ['id' => $signatureId])
		);

		return [
			[
				'TEXT' => Loc::getMessage('MAIL_USERSIGNATURE_EDIT_ACTION'),
				'ONCLICK' => 'BX.Mail.UserSignature.List.openUrl(\''.\CUtil::JSEscape($editUrl->getLocator()).'\')',
			],
			[
				'TEXT' => Loc::getMessage('MAIL_USERSIGNATURE_DELETE_ACTION'),
				'ONCLICK' => 'BX.Mail.UserSignature.List.delete(\''.$signatureId.'\')',
			],
		];
	}

	/**
	 * Actions over a shared signature. Editing goes to the single signature editor, the same screen
	 * a personal signature is edited on: it opens a shared signature with its scope switcher on and
	 * its targets filled in.
	 *
	 * @return array
	 */
	protected function buildSharedActions(array $dto): array
	{
		$editUrl = new \Bitrix\Main\Web\Uri(
			\CComponentEngine::makePathFromTemplate($this->arParams['PATH_TO_MAIL_SIGNATURE'], ['id' => $dto['id']])
		);

		return [
			[
				'TEXT' => Loc::getMessage('MAIL_USERSIGNATURE_EDIT_ACTION'),
				'ONCLICK' => 'BX.Mail.UserSignature.List.openUrl(\''.\CUtil::JSEscape($editUrl->getLocator()).'\')',
			],
			[
				'TEXT' => Loc::getMessage('MAIL_USERSIGNATURE_DELETE_ACTION'),
				'ONCLICK' => sprintf(
					'BX.Mail.UserSignature.List.deleteShared(\'%d\', %d)',
					(int)$dto['id'],
					(int)$dto['assignedMailboxCount']
				),
			],
		];
	}

	/**
	 * Formatted names of the authors of the given page.
	 *
	 * @param array<array{signature: mixed, assignments: array}> $entries
	 * @return array<int, string>
	 */
	protected function getAuthorNames(array $entries): array
	{
		$userIds = [];
		foreach ($entries as $entry)
		{
			$authorId = (int)$entry['signature']->get('CREATED_BY');
			if ($authorId > 0)
			{
				$userIds[$authorId] = true;
			}
		}

		return empty($userIds) ? [] : $this->getTargetDirectory()->getUserTitles(array_keys($userIds));
	}

	/**
	 * @return array
	 */
	protected function prepareFilter()
	{
		$filter = [
			'FILTER_ID' => $this->filterId,
			'GRID_ID' => $this->gridId,
			'FILTER' => $this->getDefaultFilterFields(),
			'DISABLE_SEARCH' => false,
			'ENABLE_LABEL' => true,
			'RESET_TO_DEFAULT_MODE' => false,
			'ENABLE_LIVE_SEARCH' => true,
		];

		return $filter;
	}

	/**
	 * Fields of the filter panel. The type of a signature is offered exactly where the column of the
	 * type is — whoever does not manage shared signatures has one kind of them and nothing to choose.
	 *
	 * @return array
	 */
	protected function getDefaultFilterFields()
	{
		$fields = [
			[
				'id' => 'ASSIGNED_TO',
				'name' => Loc::getMessage('MAIL_USERSIGNATURE_LIST_ASSIGNED_TO'),
				'default' => true,
				'type' => 'entity_selector',
				'params' => [
					'multiple' => 'Y',
					// the value of an item has to name its entity: three kinds of target share the field
					'addEntityIdToResult' => 'Y',
					'dialogOptions' => [
						'height' => 240,
						'context' => 'filter',
						'entities' => $this->getAssignmentSelectorEntities(),
					],
				],
			],
		];

		if ($this->areServiceColumnsVisible())
		{
			$fields[] = [
				'id' => 'TYPE',
				'name' => Loc::getMessage('MAIL_USERSIGNATURE_LIST_TYPE'),
				'default' => true,
				'type' => 'list',
				'items' => [
					SharedSignatureTable::SCOPE_OWNER => Loc::getMessage('MAIL_USERSIGNATURE_LIST_TYPE_OWN'),
					SharedSignatureTable::SCOPE_SHARED => Loc::getMessage('MAIL_USERSIGNATURE_LIST_TYPE_SHARED'),
				],
			];
		}

		return $fields;
	}

	/**
	 * Entities the assignment selector of the filter offers, the same ones the editor assigns a
	 * signature to. The mailboxes of the portal are the targets of a shared signature and are shown
	 * to whoever manages them — the provider guards its own data as well, and the field keeps the
	 * dialog from offering what would resolve to nothing.
	 *
	 * @return array
	 */
	protected function getAssignmentSelectorEntities(): array
	{
		$entities = [];

		if ($this->canManageSharedScope())
		{
			$entities[] = [
				'id' => MailboxProvider::PROVIDER_ENTITY_ID,
				'dynamicLoad' => true,
				'dynamicSearch' => true,
			];
		}

		$userOptions = [
			'intranetUsersOnly' => true,
			'emailUsers' => false,
			'inviteEmployeeLink' => false,
			'inviteGuestLink' => false,
		];

		$entities[] = [
			'id' => self::SELECTOR_ENTITY_USER,
			'dynamicLoad' => true,
			'dynamicSearch' => true,
			'options' => $userOptions,
		];
		$entities[] = [
			'id' => self::SELECTOR_ENTITY_DEPARTMENT,
			'dynamicLoad' => true,
			'dynamicSearch' => true,
			'options' => [
				'selectMode' => 'usersAndDepartments',
				'allowSelectRootDepartment' => true,
				'allowFlatDepartments' => true,
				'userOptions' => $userOptions,
			],
		];

		return $entities;
	}

	/**
	 * @return array
	 */
	protected function getListFilter()
	{
		$conditions = [
			$this->getListVisibilityFilter(),
		];

		$scope = $this->getScopeFieldFilter();
		if ($scope !== null)
		{
			$conditions[] = $scope;
		}

		$search = $this->getSearchFilter();
		if ($search !== null)
		{
			$conditions[] = $search;
		}

		return count($conditions) === 1 ? $conditions[0] : ['LOGIC' => 'AND', ...$conditions];
	}

	/**
	 * The state of the filter panel, read once for every field that translates it into a condition.
	 *
	 * @return array
	 */
	protected function getRequestFilter(): array
	{
		if ($this->requestFilter === null)
		{
			$filterOptions = new Bitrix\Main\UI\Filter\Options($this->filterId);
			$this->requestFilter = $filterOptions->getFilter($this->getDefaultFilterFields());
		}

		return $this->requestFilter;
	}

	/**
	 * Translates the chosen type of a signature into a condition over the scope of the model.
	 * A value the panel could not have offered is no condition at all — the field is only there for
	 * whoever manages shared signatures.
	 *
	 * @return array|null null when the type is not chosen.
	 */
	protected function getScopeFieldFilter(): ?array
	{
		if (!$this->areServiceColumnsVisible())
		{
			return null;
		}

		$scope = (string)($this->getRequestFilter()['TYPE'] ?? '');
		$known = [SharedSignatureTable::SCOPE_OWNER, SharedSignatureTable::SCOPE_SHARED];

		return in_array($scope, $known, true) ? ['=SCOPE' => $scope] : null;
	}

	/**
	 * Translates the state of the filter into a condition over the unified model.
	 * Both the field and the live search are answered from the same source as the list itself, so a
	 * signature created after the migration is found by its sender and by its text alike.
	 *
	 * @return array|null null when nothing is searched for.
	 */
	protected function getSearchFilter(): ?array
	{
		$requestFilter = $this->getRequestFilter();

		$directory = $this->getTargetDirectory();

		$targets = $this->getSelectedAssignmentTargets();
		if (!empty($targets))
		{
			// One query per type of target, the way the directory reads everything else about them.
			$signatureIds = [];
			foreach ($targets as $targetType => $targetIds)
			{
				$signatureIds = array_merge(
					$signatureIds,
					$directory->findSignatureIdsByTargetIds($targetType, $targetIds)
				);
			}

			return $this->buildIdCondition($signatureIds);
		}

		$find = trim((string)($requestFilter['FIND'] ?? ''));
		if ($find !== '')
		{
			$condition = ['LOGIC' => 'OR', ['%SIGNATURE' => $find]];

			// The lookup by a sender is asked within the rows of this screen: a match outside them is
			// dropped by the visibility condition anyway, while looking for it reads the sender binding
			// of every signature of the portal.
			$senderMatches = $directory->findSignatureIdsBySender($find, $this->getVisibleSignatureIds());
			if (!empty($senderMatches))
			{
				$condition[] = ['=ID' => $senderMatches];
			}

			return $condition;
		}

		return null;
	}

	/**
	 * Everything this screen may show, read once: the condition of the list itself, before the fields
	 * of the panel narrow it down.
	 *
	 * @return array
	 */
	protected function getListVisibilityFilter(): array
	{
		return $this->listVisibilityFilter ??= $this->getSignatureService()->buildListVisibilityFilter(
			(int)$this->arParams['USER_ID'],
			$this->isSharedSignatureInterfaceEnabled()
		);
	}

	/**
	 * IDs of the signatures of this screen — the very condition the list is selected by, expressed as
	 * identifiers for the queries that go to the assignments before it.
	 *
	 * Read whole, which the model keeps small: the personal signatures of one user, bounded by the
	 * option mail.user_signatures_limit, plus the shared ones, which are the corporate signatures of
	 * the portal.
	 *
	 * @return int[]
	 */
	protected function getVisibleSignatureIds(): array
	{
		return $this->visibleSignatureIds ??= $this->getSignatureService()->getSignatureIds(
			$this->getListVisibilityFilter()
		);
	}

	/**
	 * @param int[] $signatureIds
	 * @return array
	 */
	protected function buildIdCondition(array $signatureIds): array
	{
		$signatureIds = array_values(array_unique(array_map('intval', $signatureIds)));

		// An impossible condition is the honest answer to a query nothing matches.
		return ['=ID' => empty($signatureIds) ? 0 : $signatureIds];
	}

	/**
	 * Targets chosen in the assignment field, grouped by their type — the same types the editor
	 * writes an assignment with, so what the field asks for and what the row stores are one thing.
	 *
	 * @return array<string, int[]> target type → identifiers
	 */
	protected function getSelectedAssignmentTargets(): array
	{
		$values = $this->getRequestFilter()['ASSIGNED_TO'] ?? null;
		if (!is_array($values))
		{
			$values = ($values === null || $values === '') ? [] : [$values];
		}

		$targets = [];
		foreach ($values as $value)
		{
			$target = $this->parseSelectorValue((string)$value);
			if ($target === null)
			{
				continue;
			}

			[$targetType, $targetId] = $target;
			$targets[$targetType][] = $targetId;
		}

		return array_map(
			static fn(array $targetIds): array => array_values(array_unique($targetIds)),
			$targets
		);
	}

	/**
	 * Reads one value of the assignment selector: the panel encodes it as a pair of an entity and
	 * its identifier. A department chosen without its sub-departments carries the ":F" suffix, and
	 * the target of an assignment is the department itself either way, so the suffix falls off here.
	 *
	 * @return array{0: string, 1: int}|null null for anything the field cannot mean.
	 */
	protected function parseSelectorValue(string $value): ?array
	{
		try
		{
			$pair = \Bitrix\Main\Web\Json::decode($value);
		}
		catch (\Throwable)
		{
			return null;
		}

		if (!is_array($pair))
		{
			return null;
		}

		$targetType = self::TARGET_TYPE_BY_SELECTOR_ENTITY[(string)($pair[0] ?? '')] ?? null;
		$targetId = (int)(string)($pair[1] ?? '');

		return ($targetType !== null && $targetId > 0) ? [$targetType, $targetId] : null;
	}

	/**
	 * The single gate over everything the shared signatures bring to this screen: their rows, the
	 * type column and the service columns next to it. P8.T2 makes it read the module option, and
	 * the places it is applied stay the same whatever the source of the value turns out to be.
	 */
	protected function isSharedSignatureInterfaceEnabled(): bool
	{
		return SharedSignatureService::isSharedInterfaceEnabled();
	}

	/**
	 * Whether the type, the author and the date of change are shown: the tariff and the right to
	 * manage shared signatures, and the interface gate on top of them — with the shared signatures
	 * hidden the screen looks exactly like the list of personal ones.
	 */
	protected function areServiceColumnsVisible(): bool
	{
		return $this->isSharedSignatureInterfaceEnabled() && $this->canManageSharedScope();
	}

	protected function canManageSharedScope(): bool
	{
		$this->sharedScopeManageable ??= SharedSignatureService::canManageSharedScope();

		return $this->sharedScopeManageable;
	}

	protected function getSignatureService(): SharedSignatureService
	{
		return $this->signatureService ??= new SharedSignatureService();
	}

	protected function getTargetDirectory(): AssignmentTargetDirectory
	{
		return $this->targetDirectory ??= new AssignmentTargetDirectory();
	}
}
