<?php

if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED !== true)
{
	die;
}

use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Grid\Export\ExcelExporter;
use Bitrix\Sign\Access\ActionDictionary;
use Bitrix\Sign\Service\Container;
use Bitrix\Main\UI\PageNavigation;
use Bitrix\Main\UI\Filter\Options;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Sign\Item\UserCollection;
use Bitrix\Sign\Service\Sign\SignersList\ExcelExportService;

Loc::loadMessages(__FILE__);

CBitrixComponent::includeComponentClass('bitrix:sign.base');

final class SignB2eSignersEdit extends SignBaseComponent
{
	private const DEFAULT_GRID_ID = 'SIGN_B2E_SIGNERS_LIST_GRID_EDIT';
	private const DEFAULT_FILTER_ID = 'SIGN_B2E_SIGNERS_EDIT_FILTER';
	private const DEFAULT_PAGE_SIZE = 10;
	private const DEFAULT_NAVIGATION_KEY = 'sign-b2e-signers-edit';
	private const MAX_SIGNERS_EXPORT = 10000;
	private const USER_FETCH_CHUNK_SIZE = 500;

	private ?\Bitrix\Sign\Item\SignersList $list = null;
	private ?bool $hasSigners = null;

	private \Bitrix\Sign\Service\SignersListService $signersListService;
	private \Bitrix\Sign\Repository\UserRepository $userRepository;
	private \Bitrix\Sign\Service\Sign\UrlGeneratorService $urlGenerator;
	private \Bitrix\Sign\Service\Sign\SignersList\AccessService $accessService;
	private ExcelExportService $excelExportService;
	private \Bitrix\Sign\Service\Integration\Socialnetwork\FeedPostService $feedPostService;

	public function __construct($component = null)
	{
		parent::__construct($component);
		$this->signersListService = Container::instance()->getSignersListService();
		$this->userRepository = Container::instance()->getUserRepository();
		$this->urlGenerator = Container::instance()->getUrlGeneratorService();
		$this->accessService = Container::instance()->getSignersListAccessService();
		$this->feedPostService = Container::instance()->getFeedPostService();
		$this->excelExportService = new ExcelExportService();
	}

	public function executeComponent(): void
	{
		if (!\Bitrix\Sign\Config\Storage::instance()->isB2eAvailable())
		{
			$this->includeNotAvailableTemplate();

			return;
		}

		$listId = $this->getIntParam('LIST_ID');
		$this->list = $listId !== 0
			? $this->signersListService->getById($listId)
			: null
		;

		if ($this->list === null)
		{
			showError(Loc::getMessage('SIGN_B2E_SIGNERS_EDIT_ACCESS_DENIED'));

			return;
		}

		if (!$this->hasCurrentUserAccessToListForRead($this->list->id))
		{
			showError(Loc::getMessage('SIGN_B2E_SIGNERS_EDIT_ACCESS_DENIED'));

			return;
		}

		parent::executeComponent();
	}

	private function hasCurrentUserAccessToListForRead(int $listId): bool
	{
		return $this->accessService->hasAccessToRead($listId);
	}

	public function exec(): void
	{
		// In export mode, skip building the regular grid data (paginated query, getByIds, navigation
		// count) only when the export actually streams. On a blocking error the .default template still
		// renders, so the regular grid must be built below.
		if ($this->isExcelExportMode() && $this->prepareExcelExport())
		{
			return;
		}

		$this->setResult('SIGNERS', $this->getGridData());
		$this->setParam('COLUMNS', $this->getGridColumnList());
		$this->setParam('NAVIGATION_KEY', $this->getNavigation()->getId());
		$this->setResult('NAVIGATION_KEY', $this->getNavigation()->getId());
		$this->setParam('ADD_NEW_SIGNER_LINK', $this->urlGenerator->makeAddSignerUrl($this->list->id));
		$this->setParam('FILTER_FIELDS', $this->getFilterFieldList());
		$this->setParam('FILTER_PRESETS', $this->getFilterPresets());
		$this->setParam('GRID_ID', self::DEFAULT_GRID_ID);
		$this->setParam('FILTER_ID', self::DEFAULT_FILTER_ID);
		$this->setResult('TOTAL_COUNT', $this->getNavigation()->getRecordCount());
		$this->setResult('PAGE_NAVIGATION', $this->getNavigation());
		$this->setResult('HAS_SIGNERS', !$this->signersListService->isListEmpty($this->list->id));
		$this->setResult('CAN_ADD_SIGNER', $this->canCurrentUserEditList($this->list));
		$this->setResult('CAN_DELETE_SIGNER', $this->canCurrentUserEditList($this->list));
		$this->setResult('LIST_ID', $this->list->id);
		$this->setResult('LIST_TITLE', $this->list->title);
		$this->setResult('GROUP_ACTIONS', $this->getGroupActions());
	}

	/**
	 * @return list<array{phrase: string, handler: string, id?: string, className?: string, dataset?: array<string, string>}>
	 */
	private function getGroupActions(): array
	{
		$listId = (int)$this->list->id;
		$actions = [];

		if ($this->canCurrentUserSendByTemplate())
		{
			// the menu encodes its items itself, so JS escaping of the address is enough
			$sendUrl = CUtil::JSEscape($this->urlGenerator->makeTemplateSendUrl($listId));
			$actions[] = [
				'phrase' => 'SIGN_B2E_SIGNERS_EDIT_ACTION_SEND_BY_TEMPLATE',
				'handler' => "(new BX.Sign.V2.Grid.B2e.Signers()).openTemplateSend('{$sendUrl}')",
				'dataset' => ['testId' => 'sign-b2e-signers-edit-send-by-template-menu-item'],
			];
		}

		if ($this->canCurrentUserCreateChat($this->list))
		{
			// unlike the rest, the item stays in the menu of an empty group: the class is what hides
			// it, and JS drops the class as soon as signers appear
			$chatClassName = 'menu-popup-no-icon sign-b2e-signers-create-chat-item';
			if (!$this->hasSigners())
			{
				$chatClassName .= ' --hidden';
			}

			$actions[] = [
				'id' => "sign-b2e-signers-create-chat-{$listId}",
				'phrase' => 'SIGN_B2E_SIGNERS_EDIT_ACTION_CREATE_CHAT',
				'handler' => "this.close(); new BX.Sign.V2.Grid.B2e.Signers().createChat({$listId});",
				'className' => $chatClassName,
				'dataset' => ['testId' => "sign-signers-list-create-chat-in-toolbar-{$listId}"],
			];
		}

		if ($this->canCurrentUserWriteToFeed())
		{
			$actions[] = [
				'phrase' => 'SIGN_B2E_SIGNERS_EDIT_ACTION_WRITE_TO_FEED',
				'handler' => '(new BX.Sign.V2.Grid.B2e.Signers()).writeToFeed(' . (int)$this->list->id . ')',
				'dataset' => ['testId' => 'sign-b2e-signers-edit-write-to-feed-menu-item'],
			];
		}

		return $actions;
	}

	private function canCurrentUserSendByTemplate(): bool
	{
		// signing items are hidden for the system list by requirement
		if ($this->signersListService->isRejectedList((int)$this->list->id))
		{
			return false;
		}

		if (!$this->getAccessController()->check(ActionDictionary::ACTION_B2E_DOCUMENT_ADD))
		{
			return false;
		}

		return $this->hasSigners();
	}

	/**
	 * Unlike the signing items, offered for the system list of the refused as well.
	 */
	private function canCurrentUserWriteToFeed(): bool
	{
		return $this->feedPostService->isPostAvailable() && $this->hasSigners();
	}

	/**
	 * Emptiness of the group, asked about the group as a whole. TOTAL_COUNT of the screen cannot be
	 * used here: it is counted with the current filter of the signers grid and with an active filter
	 * does not tell whether the group has anybody at all.
	 */
	private function hasSigners(): bool
	{
		if ($this->hasSigners === null)
		{
			$this->hasSigners = $this->signersListService->listNonEmptyListIds([(int)$this->list->id]) !== [];
		}

		return $this->hasSigners;
	}

	private function isExcelExportMode(): bool
	{
		return $this->getRequest(ExcelExporter::REQUEST_PARAM_NAME) === ExcelExporter::REQUEST_PARAM_VALUE;
	}

	/**
	 * Returns true when the export streams (template switched to 'excel'), false on a blocking
	 * error (EXPORT_ERROR set, template stays '.default' so the regular grid must still render).
	 */
	private function prepareExcelExport(): bool
	{
		try
		{
			$signers = $this->signersListService->listSignersWithFilter(
				$this->list->id,
				$this->getExportFilterQuery(),
				self::MAX_SIGNERS_EXPORT + 1,
				0,
			);
			$status = $this->excelExportService->getStatus($signers->count(), self::MAX_SIGNERS_EXPORT);

			if ($status === ExcelExportService::STATUS_EMPTY)
			{
				// ERR-001/ERR-002 are indistinguishable by count; a single message covers both.
				$this->setResult('EXPORT_ERROR', (string)Loc::getMessage('SIGN_B2E_SIGNERS_EDIT_EXPORT_ERR_NO_DATA'));

				return false;
			}

			if ($status === ExcelExportService::STATUS_LIMIT_EXCEEDED)
			{
				$this->setResult('EXPORT_ERROR', (string)Loc::getMessage('SIGN_B2E_SIGNERS_EDIT_EXPORT_ERR_TOO_MANY'));

				return false;
			}

			$this->setResult('GRID_ID', self::DEFAULT_GRID_ID);
			$this->setResult('VISIBLE_COLUMNS_FOR_EXCEL', $this->getVisibleColumnsForExcel());
			$this->setResult('EXCEL_ROWS', $this->getExcelRows($signers));
			$this->setResult('EXCEL_FILE_NAME', $this->excelExportService->getFileName($this->list->title));
		}
		catch (\Throwable $e)
		{
			// Recovery is possible only before the template calls RestartBuffer().
			Container::instance()->getLogger('Component')->error('excel export build failed: ' . $e->getMessage());
			$this->setResult('EXPORT_ERROR', (string)Loc::getMessage('SIGN_B2E_SIGNERS_EDIT_EXPORT_ERR_BUILD_FAILED'));

			return false;
		}

		// Switch to streaming template only after data is ready.
		$this->setTemplateName('excel');

		return true;
	}

	private function getExportSelectedUserIds(): array
	{
		$ids = $this->getRequest('exportSelectedIds');
		if (!is_array($ids))
		{
			return [];
		}

		return array_values(array_filter(
			array_map('intval', $ids),
			static fn(int $id): bool => $id > 0,
		));
	}

	private function getExportFilterQuery(): ConditionTree
	{
		$filter = $this->getFilterQuery();

		$selectedUserIds = $this->getExportSelectedUserIds();
		if ($selectedUserIds)
		{
			$filter->whereIn('USER_ID', $selectedUserIds);
		}

		return $filter;
	}

	private function getVisibleColumnsForExcel(): array
	{
		$columns = $this->getGridColumnList();
		$visibleColumnIds = (new \Bitrix\Main\Grid\Options(self::DEFAULT_GRID_ID))->GetVisibleColumns();

		return $this->excelExportService->getVisibleColumns(
			$columns,
			is_array($visibleColumnIds) ? $visibleColumnIds : [],
		);
	}

	private function getExcelRows(\Bitrix\Sign\Item\SignersListUserCollection $signers): array
	{
		$signerIds = [];
		foreach ($signers as $signer)
		{
			$signerIds[$signer->userId] = $signer->userId;
		}
		$signersData = new UserCollection();
		foreach (array_chunk($signerIds, self::USER_FETCH_CHUNK_SIZE) as $signerIdsChunk)
		{
			foreach ($this->userRepository->getByIds($signerIdsChunk) as $signerData)
			{
				$signersData->add($signerData);
			}
		}

		$culture = \Bitrix\Main\Context::getCurrent()?->getCulture();
		$dateFormat = $culture?->getLongDateFormat() ?? 'j F Y';
		$timeFormat = $culture?->getLongTimeFormat() ?? 'H:i';

		$rows = [];
		foreach ($signers as $signer)
		{
			$signerData = $signersData->getByIdMap($signer->userId ?? 0);
			// Raw full name; escaping happens once in the excel template.
			$signerFullName = $signerData
				? Container::instance()->getUserService()->getUserName($signerData)
				: ''
			;

			$dateCreate = $signer->dateCreate ?? null;
			$dateCreateString = $dateCreate
				? FormatDate($dateFormat . ' ' . $timeFormat, $dateCreate->toUserTime()->getTimestamp())
				: ''
			;

			$rows[] = [
				'id' => $signer->userId,
				'columns' => [
					'ID' => (string)$signer->userId,
					'SIGNER' => $signerFullName,
					'DATE_CREATE' => $dateCreateString,
				],
			];
		}

		return $rows;
	}

	private function getGridColumnList(): array
	{
		return [
			[
				'id' => 'ID',
				'name' => (string)Loc::getMessage('SIGN_B2E_SIGNERS_LIST_COLUMN_ID'),
				'default' => false,
			],
			[
				'id' => 'SIGNER',
				'name' => (string)Loc::getMessage('SIGN_B2E_SIGNERS_LIST_COLUMN_SIGNER'),
				'default' => true,
			],
			[
				'id' => 'DATE_CREATE',
				'name' => (string)Loc::getMessage('SIGN_B2E_SIGNERS_LIST_COLUMN_DATE_CREATE'),
				'default' => true,
			],
		];
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

	private function getCurrentPageElements(): \Bitrix\Sign\Item\SignersListUserCollection
	{
		return $this->signersListService->listSignersWithFilter(
			$this->list->id,
			$this->getFilterQuery(),
			$this->getNavigation()->getPageSize(),
			$this->getNavigation()->getOffset(),
		);
	}

	private function decrementCurrentPage(): void
	{
		$this->getNavigation()->setCurrentPage($this->getNavigation()->getCurrentPage() - 1);
	}

	private function mapElementsToGridData(\Bitrix\Sign\Item\SignersListUserCollection $users): array
	{
		$signerIds = [];
		foreach ($users as $user)
		{
			$signerIds[$user->userId] = $user->userId;
		}
		$signers = $this->userRepository->getByIds($signerIds);

		return array_map(
			fn(\Bitrix\Sign\Item\SignersListUser $user): array => $this->mapSignerToGridData(
				$user,
				$signers,
			),
			$users->toArray(),
		);
	}

	private function mapSignerToGridData(
		\Bitrix\Sign\Item\SignersListUser $signer,
		UserCollection $signersData,
	): array
	{
		$signerData = $signersData->getByIdMap($signer->userId ?? 0);
		$personalPhoto = $signerData?->personalPhotoId;
		$signerAvatarPath = $personalPhoto ? htmlspecialcharsbx(CFile::GetPath($personalPhoto)): '';
		$signerFullName = $signerData
			? Container::instance()->getUserService()->getUserName($signerData)
			: "$signerData?->name $signerData?->lastName"
		;
		$signerFullName = htmlspecialcharsbx($signerFullName);

		return [
			'id' => $signer->userId,
			'columns' => [
				'ID' => $signer->listId.'_'.$signer->userId,
				'SIGNER' => [
					'ID' => $signer->userId,
					'FULL_NAME' => $signerFullName,
					'AVATAR_PATH' => $signerAvatarPath,
					'PROFILE_URL' => $this->urlGenerator->makeProfileUrl($signer->userId),
				],
				'DATE_CREATE' => $signer->dateCreate ?? null,
			],
		];
	}

	private function getFilterQuery(): ConditionTree
	{
		$filterData = $this->getFilterValues();
		return $this->prepareQueryFilterByGridFilterData($filterData);
	}

	private function getFilterValues(): array
	{
		return (new Options(self::DEFAULT_FILTER_ID))->getFilter($this->getFilterFieldList());
	}

	private function getFilterFieldList(): array
	{
		return [
			[
				'id' => 'SIGNER',
				'name' => (string)Loc::getMessage('SIGN_B2E_SIGNERS_LIST_FILTER_FIELD_SIGNER'),
				'default' => true,
				'type' => 'entity_selector',
				'partial' => true,
				'params' => [
					'multiple' => 'Y',
					'dialogOptions' => [
						'height' => 240,
						'entities' => [
							[
								'id' => \Bitrix\Sign\Type\Member\EntityType::USER,
								'dynamicLoad' => true,
								'dynamicSearch' => true,
								'options' => [
									'inviteEmployeeLink' => false,
								],
							],
						],
					],
				],
			],
			[
				'id' => 'DATE_CREATE',
				'name' => (string)Loc::getMessage('SIGN_B2E_SIGNERS_LIST_FILTER_FIELD_DATE_CREATE'),
				'type' => 'date',
				'default' => true,
			],
		];
	}

	private function prepareQueryFilterByGridFilterData(array $filterData): ConditionTree
	{
		$filter = Bitrix\Main\ORM\Query\Query::filter();

		$dateCreateFrom = $filterData['DATE_CREATE_from'] ?? null;
		if ($dateCreateFrom && \Bitrix\Main\Type\DateTime::isCorrect($dateCreateFrom))
		{
			$filter->where('DATE_CREATE', '>=', new \Bitrix\Main\Type\DateTime($dateCreateFrom));
		}

		$dateCreateTo = $filterData['DATE_CREATE_to'] ?? null;
		if ($dateCreateTo && \Bitrix\Main\Type\DateTime::isCorrect($dateCreateTo))
		{
			$filter->where('DATE_CREATE', '<=', new \Bitrix\Main\Type\DateTime($dateCreateTo));
		}

		$signerIds = $this->ensureArray($filterData['SIGNER'] ?? []);
		if ($signerIds)
		{
			$filter->whereIn('USER_ID', $signerIds);
		}

		$find = $filterData['FIND'] ?? null;
		if ($find)
		{
			$words = array_slice(explode(' ', $find), 0, 3);
			foreach ($words as $word)
			{
				$filter->whereLike('USER_SEARCH_NAME', '%' . $word . '%');
			}
		}

		return $filter;
	}

	private function ensureArray($value): array
	{
		return is_array($value) ? $value : [$value];
	}

	private function canCurrentUserEditList(\Bitrix\Sign\Item\SignersList $list): bool
	{
		return $this->accessService->hasAccessToEdit($list->id);
	}

	private function getNavigation(): PageNavigation
	{
		if (!isset($this->arResult['PAGE_NAVIGATION']))
		{
			return $this->prepareNavigation();
		}

		return $this->arResult['PAGE_NAVIGATION'];
	}

	private function prepareNavigation(): PageNavigation
	{
		$pageSize = (int)$this->getParam('PAGE_SIZE');
		$pageSize = $pageSize > 0 ? $pageSize : self::DEFAULT_PAGE_SIZE;
		$navigationKey = $this->getParam('NAVIGATION_KEY') ?? self::DEFAULT_NAVIGATION_KEY;

		$pageNavigation = new \Bitrix\Sign\Util\UI\PageNavigation($navigationKey);
		$pageNavigation->setPageSize($pageSize)
			->setRecordCount($this->signersListService->countSignersWithFilter(
				$this->getIntParam('LIST_ID'),
				$this->getFilterQuery(),
			))
			->setPageSizes([10, 20, 50, 100, 500])
			->allowAllRecords(false)
			->initFromUri()
		;

		$this->arResult['PAGE_NAVIGATION'] = $pageNavigation;

		return $pageNavigation;
	}

	private function canCurrentUserCreateChat(\Bitrix\Sign\Item\SignersList $list): bool
	{
		if (!\Bitrix\Main\Loader::includeModule('im'))
		{
			return false;
		}

		return $this->accessService->hasAccessToRead($list->id);
	}

	private function getFilterPresets(): array
	{
		return [];
	}
}
