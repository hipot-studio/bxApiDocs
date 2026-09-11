<?php

if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED !== true)
{
	die;
}

use Bitrix\Main\DB\Order;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Grid;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\UI\Filter\Options;
use Bitrix\Main\UI\PageNavigation;
use Bitrix\Sign\Access\ActionDictionary;
use Bitrix\Sign\Access\Model\UserModel;
use Bitrix\Sign\Access\Permission\SignPermissionDictionary;
use Bitrix\Sign\Access\Service\RolePermissionService;
use Bitrix\Sign\Config\Storage;
use Bitrix\Sign\Repository\UserRepository;
use Bitrix\Sign\Service\Container;
use Bitrix\Sign\Item\UserCollection;
use Bitrix\Sign\Type\SignersList\SortField;

Loc::loadMessages(__FILE__);

CBitrixComponent::includeComponentClass('bitrix:sign.base');

final class SignB2eSignersList extends SignBaseComponent
{
	private const DEFAULT_GRID_ID = 'SIGN_B2E_SIGNERS_LIST_GRID';
	private const DEFAULT_FILTER_ID = 'SIGN_B2E_SIGNERS_LIST_FILTER';
	private const DEFAULT_NAVIGATION_KEY = 'sign-b2e-signers-list';
	private const DEFAULT_PAGE_SIZE = 10;
	private const PIN_COLUMN_ID = 'PIN';

	// No typed field plus the stable ID DESC the domain applies anyway.
	private const DEFAULT_DATA_ORDER = [null, Order::Desc];

	// JS variable of the grid controller: action handlers are evaluated in the page scope.
	private const CLIENT_CONTROLLER = 'listGrid';

	private readonly \Bitrix\Sign\Service\SignersListService $signersListService;
	private readonly UserRepository $userRepository;
	private readonly \Bitrix\Sign\Service\Sign\UrlGeneratorService $urlGeneratorService;
	private readonly \Bitrix\Sign\Service\Integration\Socialnetwork\FeedPostService $feedPostService;
	private UserModel $currentUserAccessModel;
	/** @var array<int|string, string|null> */
	private array $currentUserPermissionValuesCache = [];
	/** @var array<string, mixed>|null */
	private ?array $sortRequest = null;
	private ?bool $canCurrentUserAddDocument = null;
	private ?bool $isFeedPostAvailable = null;

	private bool $isImModuleIncluded = false;

	private array $notEmptyListIds = [];

	public function __construct($component = null)
	{
		parent::__construct($component);
		$this->signersListService = Container::instance()->getSignersListService();
		$this->userRepository = Container::instance()->getUserRepository();
		$this->urlGeneratorService = Container::instance()->getUrlGeneratorService();
		$this->feedPostService = Container::instance()->getFeedPostService();
		$this->isImModuleIncluded = Loader::includeModule('im');
	}

	public function executeComponent(): void
	{
		if (!Storage::instance()->isB2eAvailable())
		{
			$this->includeNotAvailableTemplate();

			return;
		}

		$accessController = $this->getAccessController();
		if (!$accessController->check(ActionDictionary::ACTION_B2E_SIGNERS_LIST_READ))
		{
			showError('Access denied');

			return;
		}

		parent::executeComponent();
	}

	public function exec(): void
	{
		$this->ensurePinColumnIsVisible();
		$this->setResult('NAVIGATION_KEY', $this->getNavigation()->getId());
		$this->setResult('CURRENT_PAGE', $this->getNavigation()->getCurrentPage());
		$this->setParam('COLUMNS', $this->getGridColumnList());
		$this->setParam('SORT', $this->getGridSortState());
		$this->setParam('FILTER_FIELDS', $this->getFilterFieldList());
		$this->setParam('FILTER_PRESETS', []);
		$this->setParam('GRID_ID', self::DEFAULT_GRID_ID);
		$this->setParam('FILTER_ID', self::DEFAULT_FILTER_ID);
		$this->setResult('TOTAL_COUNT', $this->getNavigation()->getRecordCount());
		$this->setResult('SIGNERS_LISTS', $this->getGridData());
		$this->setResult('PAGE_SIZE', $this->getNavigation()->getPageSize());
		$this->setResult('PAGE_NAVIGATION', $this->getNavigation());
		$this->setResult('CAN_ADD_LIST', $this->canCreateList());
	}

	private function ensurePinColumnIsVisible(): void
	{
		$options = (new Grid\Options(self::DEFAULT_GRID_ID))->getOptions();
		if (!is_array($options['views'] ?? null))
		{
			return;
		}

		$changed = false;
		foreach ($options['views'] as &$view)
		{
			if (!is_string($view['columns'] ?? null) || $view['columns'] === '')
			{
				continue;
			}

			$columns = explode(',', $view['columns']);
			if (!in_array(self::PIN_COLUMN_ID, $columns, true))
			{
				array_unshift($columns, self::PIN_COLUMN_ID);
				$view['columns'] = implode(',', $columns);
				$changed = true;
			}
		}
		unset($view);

		if ($changed)
		{
			\CUserOptions::SetOption('main.interface.grid', self::DEFAULT_GRID_ID, $options);
		}
	}

	private function getNavigation(): PageNavigation
	{
		if (!isset($this->arResult['PAGE_NAVIGATION']))
		{
			return $this->prepareNavigation();
		}

		return $this->arResult['PAGE_NAVIGATION'];
	}

	private function getGridData(): array
	{
		$currentPageElements = $this->getCurrentPageElements();

		if ($currentPageElements->isEmpty() && $this->getNavigation()->getCurrentPage() > 1)
		{
			$this->decrementCurrentPage();
			$currentPageElements = $this->getCurrentPageElements();
		}

		return $this->mapElementsToGridData($currentPageElements);
	}

	private function getCurrentPageElements(): \Bitrix\Sign\Item\SignersListCollection
	{
		[$sortField, $sortDirection] = $this->getDataOrder();

		return $this->signersListService->listWithFilter(
			$this->getFilterQuery(),
			$this->getNavigation()->getPageSize(),
			$this->getNavigation()->getOffset(),
			$sortField,
			$sortDirection,
			$this->getCurrentUserId(),
		);
	}

	private function decrementCurrentPage(): void
	{
		$this->getNavigation()->setCurrentPage($this->getNavigation()->getCurrentPage() - 1);
	}

	private function mapElementsToGridData(\Bitrix\Sign\Item\SignersListCollection $lists): array
	{
		$this->notEmptyListIds = $this->signersListService->listNotEmptyListIds($lists->getIds());

		$responsibleIds = [];
		foreach ($lists as $list)
		{
			$responsibleId = $this->getResponsibleByList($list);
			$responsibleIds[$responsibleId] = $responsibleId;
		}
		$responsibleUsers = $this->userRepository->getByIds($responsibleIds);

		// pins and emptiness of the whole page are read in one call each, never per row
		$listIds = $lists->getIds();
		$pinnedListIds = $this->signersListService->listPinnedListIds($this->getCurrentUserId(), $listIds);
		$nonEmptyListIds = $this->signersListService->listNonEmptyListIds($listIds);

		return array_map(
			fn(\Bitrix\Sign\Item\SignersList $list): array => $this->mapListToGridData(
				$list,
				$responsibleUsers,
				in_array((int)$list->id, $pinnedListIds, true),
				in_array((int)$list->id, $nonEmptyListIds, true),
			),
			$lists->toArray(),
		);
	}

	private function mapListToGridData(
		\Bitrix\Sign\Item\SignersList $list,
		UserCollection $responsibleUsers,
		bool $isPinned,
		bool $hasSigners,
	): array
	{
		$responsibleData = $responsibleUsers->getByIdMap($this->getResponsibleByList($list) ?? 0);
		$personalPhoto = $responsibleData?->personalPhotoId;
		$responsibleAvatarPath = $personalPhoto
			? htmlspecialcharsbx(CFile::GetPath($personalPhoto))
			: ''
		;
		$responsibleName = $responsibleData?->name ?? '';
		$responsibleLastName = $responsibleData?->lastName ?? '';
		$responsibleFullName = "$responsibleName $responsibleLastName";
		$responsibleId = $responsibleData?->id ?? 0;

		$row = [
			'id' => $list->id,
			'columns' => [
				'ID' => $list->id,
				'TITLE' => $list->title,
				'DATE_MODIFY' => $list->dateModify ?? $list->dateCreate ?? null,
				'RESPONSIBLE' => [
					'ID' => $responsibleId,
					'FULL_NAME' => $responsibleFullName,
					'AVATAR_PATH' => $responsibleAvatarPath,
				],
			],
			'access' => [
				'canEdit' => $this->canCurrentUserEditList($list),
				'canDelete' => $this->canCurrentUserDeleteList($list),
				'canCopy' => $this->canCurrentUserCopyList($list),
				'canCreateChat' => $this->canCreateChat($list),
			],
			'isPinned' => $isPinned,
			'hasSigners' => $hasSigners,
		];

		$row['actions'] = $this->getRowActions($row);
		$row['cellActions'] = $this->getRowCellActions($row);

		return $row;
	}

	/**
	 * Unavailable items are absent, not disabled: the template renders the returned set as is.
	 *
	 * @return list<array{phrase?: string, handler?: string, separator?: bool, dataset?: array<string, string>}>
	 */
	private function getRowActions(array $row): array
	{
		$employeeActions = $this->getRowEmployeeActions($row);
		$listActions = $this->getRowListActions($row);

		if ($employeeActions !== [] && $listActions !== [])
		{
			return [...$employeeActions, ['separator' => true], ...$listActions];
		}

		return [...$employeeActions, ...$listActions];
	}

	/**
	 * @return list<array{phrase: string, handler: string, dataset?: array<string, string>}>
	 */
	private function getRowEmployeeActions(array $row): array
	{
		$listId = (int)$row['columns']['ID'];
		$controller = self::CLIENT_CONTROLLER;
		$hasSigners = (bool)($row['hasSigners'] ?? false);

		$actions = [];

		if ($hasSigners && $this->canCurrentUserAddDocument())
		{
			// the grid encodes the action list itself (Json::encode + HtmlFilter::encode)
			$sendUrl = CUtil::JSEscape($this->urlGeneratorService->makeTemplateSendUrl($listId));
			$actions[] = [
				'phrase' => 'SIGN_B2E_SIGNERS_LIST_ACTION_SEND_BY_TEMPLATE',
				'handler' => "{$controller}.openTemplateSend('{$sendUrl}')",
				'dataset' => ['testid' => 'sign-b2e-signers-list-row-send-by-template'],
			];
		}

		if ($row['access']['canCreateChat'] ?? false)
		{
			$actions[] = [
				'phrase' => 'SIGN_B2E_SIGNERS_LIST_ACTION_CREATE_CHAT',
				'handler' => "{$controller}.createChat({$listId})",
				'dataset' => ['testid' => "sign-signers-list-create-chat-{$listId}"],
			];
		}

		if ($hasSigners && $this->isFeedPostAvailable())
		{
			$actions[] = [
				'phrase' => 'SIGN_B2E_SIGNERS_LIST_ACTION_WRITE_TO_FEED',
				'handler' => "{$controller}.writeToFeed({$listId})",
				'dataset' => ['testid' => 'sign-b2e-signers-list-row-write-to-feed'],
			];
		}

		return $actions;
	}

	/**
	 * @return list<array{phrase: string, handler: string, dataset?: array<string, string>}>
	 */
	private function getRowListActions(array $row): array
	{
		$listId = (int)$row['columns']['ID'];
		$escapedTitle = CUtil::JSEscape((string)$row['columns']['TITLE']);
		$controller = self::CLIENT_CONTROLLER;

		$actions = [];

		if ($row['access']['canEdit'] ?? false)
		{
			$actions[] = [
				'phrase' => 'SIGN_B2E_SIGNERS_LIST_ACTION_RENAME',
				'handler' => "{$controller}.renameList({$listId}, '{$escapedTitle}')",
				'dataset' => ['testid' => 'sign-b2e-signers-list-row-rename'],
			];
		}

		if ($row['access']['canCopy'] ?? false)
		{
			$actions[] = [
				'phrase' => 'SIGN_B2E_SIGNERS_LIST_ACTION_COPY',
				'handler' => "{$controller}.copyList({$listId})",
				'dataset' => ['testid' => 'sign-b2e-signers-list-row-copy'],
			];
		}

		if ($row['access']['canDelete'] ?? false)
		{
			$actions[] = [
				'phrase' => 'SIGN_B2E_SIGNERS_LIST_ACTION_DELETE',
				'handler' => "{$controller}.deleteList({$listId}, '{$escapedTitle}')",
				'dataset' => ['testid' => 'sign-b2e-signers-list-row-delete'],
			];
		}

		return $actions;
	}

	/**
	 * main.ui.grid evaluates a cell action handler as an expression and expects a function, hence bind().
	 * No user-controlled value may be interpolated into that expression.
	 *
	 * @return array<string, list<array<string, mixed>>>
	 */
	private function getRowCellActions(array $row): array
	{
		$listId = (int)$row['columns']['ID'];
		$isPinned = (bool)$row['isPinned'];
		$controller = self::CLIENT_CONTROLLER;

		$classList = [Grid\CellActions::PIN];
		if ($isPinned)
		{
			$classList[] = Grid\CellActionState::ACTIVE;
		}

		$title = $isPinned
			? Loc::getMessage('SIGN_B2E_SIGNERS_LIST_ACTION_UNPIN')
			: Loc::getMessage('SIGN_B2E_SIGNERS_LIST_ACTION_PIN')
		;

		return [
			self::PIN_COLUMN_ID => [
				[
					'class' => $classList,
					'attributes' => [
						'title' => (string)$title,
						'data-testid' => 'sign-b2e-signers-list-row-pin',
					],
					'events' => [
						'click' => sprintf(
							'%1$s.togglePin.bind(%1$s, %2$d, %3$s)',
							$controller,
							$listId,
							$isPinned ? 'true' : 'false',
						),
					],
				],
			],
		];
	}

	private function getGridColumnList(): array
	{
		return [
			[
				'id' => self::PIN_COLUMN_ID,
				'name' => '',
				'default' => true,
				'class' => 'sign-signers-list-grid-header-pin',
				'resizeable' => false,
			],
			[
				'id' => 'ID',
				'name' => (string)Loc::getMessage('SIGN_B2E_SIGNERS_LIST_COLUMN_ID'),
				'default' => false,
				'sort' => SortField::Id->value,
			],
			[
				'id' => 'TITLE',
				'name' => (string)Loc::getMessage('SIGN_B2E_SIGNERS_LIST_COLUMN_TITLE'),
				'default' => true,
				'sort' => SortField::Title->value,
			],
			[
				'id' => 'RESPONSIBLE',
				'name' => (string)Loc::getMessage('SIGN_B2E_SIGNERS_LIST_COLUMN_RESPONSIBLE'),
				'default' => true,
			],
			[
				'id' => 'DATE_MODIFY',
				'name' => (string)Loc::getMessage('SIGN_B2E_SIGNERS_LIST_COLUMN_DATE_MODIFY'),
				'default' => true,
				'sort' => SortField::DateModify->value,
			],
		];
	}

	/**
	 * Sort state handed to main.ui.grid. The grid prefers this parameter over the sorting saved
	 * in the user option, so the highlighted column always matches the order that really reached
	 * the query - including the ID DESC fallback of a rejected request.
	 *
	 * @return array<string, string>
	 */
	private function getGridSortState(): array
	{
		[$sortField, $sortDirection] = $this->getDataOrder();

		return [
			($sortField ?? SortField::Id)->value => mb_strtolower($sortDirection->value),
		];
	}

	/**
	 * @return array{0: ?SortField, 1: Order}
	 */
	private function getDataOrder(): array
	{
		return self::resolveDataOrder($this->getSortRequest());
	}

	/**
	 * Read once per render: the column list and the selection must not disagree about the sort.
	 *
	 * @return array<string, mixed>
	 */
	private function getSortRequest(): array
	{
		return $this->sortRequest ??= ((new Grid\Options(self::DEFAULT_GRID_ID))->getSorting()['sort'] ?? []);
	}

	/**
	 * Normalizes an untrusted grid sort request into a typed [field, direction] pair.
	 * Only enum values leave this method, so no request string can reach the ORDER BY clause.
	 *
	 * @return array{0: ?SortField, 1: Order}
	 */
	private static function resolveDataOrder(mixed $sort): array
	{
		if (!is_array($sort))
		{
			return self::DEFAULT_DATA_ORDER;
		}

		foreach ($sort as $column => $direction)
		{
			$field = is_string($column) ? SortField::tryFrom($column) : null;
			if ($field === null)
			{
				continue;
			}

			$order = is_string($direction) ? Order::tryFrom(mb_strtoupper($direction)) : null;
			if ($order === null)
			{
				continue;
			}

			return [$field, $order];
		}

		return self::DEFAULT_DATA_ORDER;
	}

	private function getFilterFieldList(): array
	{
		return [
			[
				'id' => 'TITLE',
				'name' => (string)Loc::getMessage('SIGN_B2E_SIGNERS_LIST_FILTER_FIELD_TITLE'),
				'default' => true,
			],
			[
				'id' => 'DATE_MODIFY',
				'name' => (string)Loc::getMessage('SIGN_B2E_SIGNERS_LIST_FILTER_FIELD_DATE_MODIFY'),
				'type' => 'date',
				'default' => true,
			],
		];
	}

	private function prepareNavigation(): PageNavigation
	{
		$pageSize = (int)$this->getParam('PAGE_SIZE');
		$pageSize = $pageSize > 0 ? $pageSize : self::DEFAULT_PAGE_SIZE;
		$navigationKey = $this->getParam('NAVIGATION_KEY') ?? self::DEFAULT_NAVIGATION_KEY;

		$pageNavigation = new \Bitrix\Sign\Util\UI\PageNavigation($navigationKey);
		$pageNavigation->setPageSize($pageSize)
			->setRecordCount($this->signersListService->countListsWithFilter($this->getFilterQuery()))
			->allowAllRecords(false)
			->initFromUri()
		;

		return $pageNavigation;
	}

	private function getFilterQuery(): ConditionTree
	{
		$filterData = $this->getFilterValues();

		$queryFilter = $this->prepareQueryFilterByGridFilterData($filterData);

		return $this->prepareQueryFilterByListPermission($queryFilter);
	}

	private function getFilterValues(): array
	{
		$options = new Options(self::DEFAULT_FILTER_ID);

		return $options->getFilter($this->getFilterFieldList());
	}

	private function prepareQueryFilterByGridFilterData(array $filterData): ConditionTree
	{
		$filter = Bitrix\Main\ORM\Query\Query::filter();

		$dateModifyFrom = $filterData['DATE_MODIFY_from'] ?? null;
		if ($dateModifyFrom && \Bitrix\Main\Type\DateTime::isCorrect($dateModifyFrom))
		{
			$filter->where('DATE_MODIFY', '>=', new \Bitrix\Main\Type\DateTime($dateModifyFrom));
		}

		$dateModifyTo = $filterData['DATE_MODIFY_to'] ?? null;
		if ($dateModifyTo && \Bitrix\Main\Type\DateTime::isCorrect($dateModifyTo))
		{
			$filter->where('DATE_MODIFY', '<=', new \Bitrix\Main\Type\DateTime($dateModifyTo));
		}

		$title = $filterData['TITLE'] ?? $filterData['FIND'] ?? null;
		if ($title)
		{
			$filter->whereLike('TITLE', '%' . $title . '%');
		}

		return $filter;
	}

	private function canCreateList(): bool
	{
		return $this
			->getAccessController()
			->check(ActionDictionary::ACTION_B2E_SIGNERS_LIST_ADD)
		;
	}

	private function canCurrentUserAddDocument(): bool
	{
		return $this->canCurrentUserAddDocument ??= $this
			->getAccessController()
			->check(ActionDictionary::ACTION_B2E_DOCUMENT_ADD)
		;
	}

	/**
	 * The feed has no permission of its own, so availability is all the item can be decided by.
	 */
	private function isFeedPostAvailable(): bool
	{
		return $this->isFeedPostAvailable ??= $this->feedPostService->isPostAvailable();
	}

	private function getCurrentUserAccessModel(): UserModel
	{
		$currentUserId = CurrentUser::get()->getId();

		if ($currentUserId < 1)
		{
			throw new \Bitrix\Main\SystemException('Current user is not authorized');
		}

		$this->currentUserAccessModel ??= UserModel::createFromId($currentUserId);

		return $this->currentUserAccessModel;
	}

	private function getCurrentUserId(): int
	{
		return $this->getCurrentUserAccessModel()->getUserId();
	}

	private function prepareQueryFilterByListPermission(ConditionTree $queryFilter): ConditionTree
	{
		if (!Loader::includeModule('crm'))
		{
			return $queryFilter;
		}

		$user = $this->getCurrentUserAccessModel();
		if ($user->isAdmin())
		{
			return $queryFilter;
		}

		$listReadPermission = $this->getValueForPermissionFromCurrentUser(SignPermissionDictionary::SIGN_B2E_SIGNERS_LIST_READ);

		return match ($listReadPermission)
		{
			CCrmPerms::PERM_ALL => $queryFilter,
			CCrmPerms::PERM_SELF => $queryFilter->where('CREATED_BY_ID', $user->getUserId()),
			CCrmPerms::PERM_DEPARTMENT => $queryFilter->whereIn('CREATED_BY_ID', $user->getUserDepartmentMembers()),
			CCrmPerms::PERM_SUBDEPARTMENT => $queryFilter->whereIn('CREATED_BY_ID', $user->getUserDepartmentMembers(true)),
			default => $queryFilter->where('CREATED_BY_ID', 0),
		};
	}

	private function canCurrentUserEditList(\Bitrix\Sign\Item\SignersList $list): bool
	{
		return $this->hasCurrentUserAccessToPermissionByItemWithOwnerId(
			$list->getOwnerId(),
			SignPermissionDictionary::SIGN_B2E_SIGNERS_LIST_EDIT,
		);
	}

	private function canCurrentUserDeleteList(\Bitrix\Sign\Item\SignersList $list): bool
	{
		return $this->hasCurrentUserAccessToPermissionByItemWithOwnerId(
			$list->getOwnerId(),
			SignPermissionDictionary::SIGN_B2E_SIGNERS_LIST_DELETE,
		);
	}

	private function canCurrentUserCopyList(\Bitrix\Sign\Item\SignersList $list): bool
	{
		if (!$this->canCreateList())
		{
			return false;
		}

		return $this->hasCurrentUserAccessToPermissionByItemWithOwnerId(
			$list->getOwnerId(),
			SignPermissionDictionary::SIGN_B2E_SIGNERS_LIST_READ,
		);
	}

	private function canCreateChat(\Bitrix\Sign\Item\SignersList $list): bool
	{
		if (!$this->isImModuleIncluded)
		{
			return false;
		}

		if (!in_array($list->id, $this->notEmptyListIds))
		{
			return false;
		}

		return $this->hasCurrentUserAccessToPermissionByItemWithOwnerId(
			$list->getOwnerId(),
			SignPermissionDictionary::SIGN_B2E_SIGNERS_LIST_READ,
		);
	}

	private function hasCurrentUserAccessToPermissionByItemWithOwnerId(int $itemOwnerId, int|string $permissionId): bool
	{
		$userAccessModel = $this->getCurrentUserAccessModel();
		if ($userAccessModel->isAdmin())
		{
			return true;
		}

		if (!Loader::includeModule('crm'))
		{
			return false;
		}


		$permission = $this->getValueForPermissionFromCurrentUser($permissionId);

		return match ($permission) {
			CCrmPerms::PERM_ALL => true,
			CCrmPerms::PERM_SELF => $itemOwnerId === $userAccessModel->getUserId(),
			CCrmPerms::PERM_DEPARTMENT => in_array($itemOwnerId, $userAccessModel->getUserDepartmentMembers(), true),
			CCrmPerms::PERM_SUBDEPARTMENT => in_array($itemOwnerId, $userAccessModel->getUserDepartmentMembers(true), true),
			default => false,
		};
	}

	private function getValueForPermissionFromCurrentUser(string|int $permissionId): ?string
	{
		$permissionService = new RolePermissionService();

		$this->currentUserPermissionValuesCache[$permissionId] ??= $permissionService->getValueForPermission(
			$this->getCurrentUserAccessModel()->getRoles(),
			$permissionId,
		);

		return $this->currentUserPermissionValuesCache[$permissionId];
	}

	private function getResponsibleByList(\Bitrix\Sign\Item\SignersList $list): ?int
	{
		return $list->modifiedById ?? $list->createdById;
	}
}
