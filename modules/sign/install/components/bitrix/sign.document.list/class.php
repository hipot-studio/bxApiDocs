<?php
if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\Main\Application;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\DB\Order;
use Bitrix\Main\Engine\Contract\Controllerable;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\ErrorCollection;
use Bitrix\Main\Grid;
use Bitrix\Main\Grid\Column\Color;
use Bitrix\Main\Grid\Export\ExcelExporter;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Context;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\ORM\Query\Filter\Condition;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\SystemException;
use Bitrix\Main\UI\PageNavigation;
use Bitrix\Main\UserTable;
use Bitrix\Main\UI\Filter;
use Bitrix\Sign\Access\AccessController;
use Bitrix\Sign\Access\ActionDictionary;
use Bitrix\Sign\Callback\Handler;
use Bitrix\Sign\Config\Storage;
use Bitrix\Sign\Connector\Crm\MyCompany;
use Bitrix\Sign\Document\Entity\SmartB2e;
use Bitrix\Sign\Exception\ObjectNotFoundException;
use Bitrix\Sign\Integration\CRM\Entity;
use Bitrix\Sign\Internal\Document\Folder\DocumentFolderRelationTable;
use Bitrix\Sign\Item;
use Bitrix\Sign\Item\MemberCollection;
use Bitrix\Sign\Repository\DocumentRepository;
use Bitrix\Sign\Repository\MemberRepository;
use Bitrix\Sign\Service\Container;
use Bitrix\Sign\Type;
use Bitrix\Sign\Type\Document\Folder\EntityType as FolderEntityType;
use Bitrix\Sign\Type\EntityFileCode;
use Bitrix\Sign\Type\Member\EntityType;
use Bitrix\Sign\Type\Member\Role;
use Bitrix\Sign\Type\MemberStatus;
use Bitrix\Sign\Type\MySafeSortField;
use Bitrix\Sign\Ui;
use Bitrix\Sign\Util\Request\File;

Loc::loadMessages(__FILE__);

CBitrixComponent::includeComponentClass('bitrix:sign.base');

class SignUserDocumentListComponent extends SignBaseComponent implements Controllerable
{
	private const SAFE_EXPORT_ERROR_SESSION_KEY = 'sign.safe.export.error.message';

	private const PERSONAL_TYPE = 'personal';
	private const PERSONAL_DOCUMENT_GRID_ID = 'DOCUMENT_GRID_ID_PERSONAL';
	private const PERSONAL_DOCUMENT_FILTER_ID = 'DOCUMENT_FILTER_ID_PERSONAL';

	private const DOCUMENT_TYPE = 'document';
	private const DOCUMENT_DOCUMENT_GRID_ID = 'DOCUMENT_GRID_ID_DOCUMENT';
	private const DOCUMENT_DOCUMENT_FILTER_ID = 'DOCUMENT_FILTER_ID_DOCUMENT';

	private const SAFE_TYPE = 'safe';
	private const SAFE_DOCUMENT_GRID_ID = 'DOCUMENT_GRID_ID_SAFE';
	// A separate grid id for the inside-a-folder view so its sorting is stored apart
	// from the root grid: sorting the root must not reorder a folder's content and
	// vice versa. The shared SAFE_DOCUMENT_FILTER_ID keeps the filter cross-level.
	private const SAFE_FOLDER_DOCUMENT_GRID_ID = 'DOCUMENT_GRID_ID_SAFE_FOLDER';
	private const SAFE_DOCUMENT_FILTER_ID = 'DOCUMENT_FILTER_ID_SAFE';

	// Allowlist of sortable safe-grid columns: grid sort token => typed ORM field.
	// An untrusted sort request can only resolve to a value present here, so it can
	// never inject a raw string into the ORDER BY clause.
	private const SAFE_SORTABLE_COLUMNS = [
		'title' => MySafeSortField::DocumentTitle,
		'dateSign' => MySafeSortField::DateSign,
	];

	// Fail-safe order used when the safe-grid sort request resolves to nothing
	// valid: no typed field plus the provider default direction.
	private const SAFE_DEFAULT_DATA_ORDER = [null, Order::Desc];

	private const CURRENT_TYPE = 'current';
	private const CURRENT_DOCUMENT_GRID_ID = 'DOCUMENT_GRID_ID_CURRENT';
	private const CURRENT_DOCUMENT_FILTER_ID = 'DOCUMENT_FILTER_ID_CURRENT';

	private const DOCUMENT_CREATOR_ICON_SIZE = ['width' => 42, 'height' => 42];
	private const PATH_TO_USER_PROFILE_TEMPLATE = '/company/personal/user/#USER_ID#/';
	private const URL_TO_USER_PROFILE_TEMPLATE_USER_KEY = 'USER_ID';

	private const DEFAULT_GRID_ID = 'DEFAULT_GRID_ID';
	private const DEFAULT_PAGE_SIZE = 10;
	private const DEFAULT_NAV_KEY = "sign-document-list-nav";
	private const DEFAULT_FILTER_ID = 'DEFAULT_FILTER_ID';
	private const DEFAULT_RESULT_FILE_DOWNLOAD_URL_TEMPLATE_HASH_KEY = 'MEMBER_HASH';
	private const DEFAULT_RESULT_FILE_DOWNLOAD_URL_TEMPLATE =
		'/bitrix/services/main/ajax.php?action=sign.document.getFileForSrc&documentHash=#MEMBER_HASH#'
	;
	private const PREFIX_FOR_SHORT_FILTER_MEMBER_STATUS = 'member_status__';
	private const BANNER_OPTION_CLOSE_PERSONAL = 'show_b2e_personal_grid_banner';
	private const BANNER_OPTION_CLOSE_SAFE = 'show_b2e_safe_grid_banner';
	private const BANNER_OPTION_CLOSE_CURRENT = 'show_b2e_current_grid_banner';
	private const EXCEL_DEFAULT_FILE_NAME = 'sign-document-members';

	private const MAX_B2E_SIGNER_MEMBERS = 10000;
	private const SAFE_FOLDER_PEOPLE_PREVIEW_LIMIT = 3;
	private const SAFE_FOLDER_COMPANY_DOCUMENT_BATCH_LIMIT = 100;
	private const SAFE_FOLDER_COMPANY_PREVIEW_LIMIT = 3;
	private const SAFE_FOLDER_COMPANY_DOCUMENT_SCAN_LIMIT = 300;
	private const MAX_B2E_REVIEWERS = 20;
	private const MAX_B2E_FIRST_SIGNER = 1;
	private const MAX_B2E_EDITOR = 1;

	// Bounded row limit for the safe excel export. Unlike getMaxB2eDocumentMembers() (the per-document
	// role ceiling), the safe export counts member rows across many documents, so it carries its own
	// business limit. The export never streams a partial file: a set larger than this is refused with
	// a "refine the filter" message instead of being truncated.
	private const SAFE_EXPORT_LIMIT = 10000;

	private const ROLE_RELEVANCE = [
		Role::REVIEWER => 100,
		Role::EDITOR => 200,
		Role::ASSIGNEE => 300,
		Role::SIGNER => 400,
	];

	private const STATUS_RELEVANCE = [
		MemberStatus::READY => 100,
		MemberStatus::STOPPABLE_READY => 100,
		MemberStatus::PROCESSING => 100,
		MemberStatus::WAIT => 200,
		MemberStatus::REFUSED => 300,
		MemberStatus::STOPPED => 400,
		MemberStatus::DONE => 500,
	];

	protected ErrorCollection $errors;
	protected ?string $type;
	protected ?int $entityId;
	protected array $companyData = [];

	private array $usersDataForGridById = [];

	/** @var array<string, mixed>|null memoized raw safe-grid sort request for the current render */
	private ?array $safeSortRequest = null;

	private MemberRepository $memberRepository;
	private DocumentRepository $documentRepository;

	private \Bitrix\Sign\Service\Sign\DocumentService $documentService;
	private \Bitrix\Sign\Service\Sign\MemberService $memberService;
	private \Bitrix\Sign\Service\Sign\Document\Safe\ListService $safeListService;
	private \Bitrix\Sign\Service\Sign\Document\Safe\AccessService $safeAccessService;

	// Batched safe company cache: CRM SmartB2e entity id => {id,title}|null (see resolveCompaniesByDocuments()).
	private array $safeCompanyByEntityId = [];

	// Safe excel export error message (empty set / limit exceeded / access denied / build failure).
	// When set, exec() shows it instead of switching to the excel template (no partial file).
	private ?string $safeExportErrorMessage = null;

	// Safe excel export: folderId => title, batch-resolved once (SafeFolderRepository::getTitlesByIds)
	// for the "Folder" column and the file name, so neither issues a per-row folder query.
	private array $safeExportFolderTitles = [];

	public function __construct($component = null)
	{
		parent::__construct($component);
		$this->errors = new ErrorCollection();
		$this->memberRepository = Container::instance()->getMemberRepository();
		$this->documentRepository = Container::instance()->getDocumentRepository();
		$this->documentService = Container::instance()->getDocumentService();
		$this->memberService = Container::instance()->getMemberService();
		$this->safeListService = Container::instance()->getSafeListService();
		$this->safeAccessService = Container::instance()->getSafeAccessService();
	}

	public function executeComponent(): void
	{
		if (!Storage::instance()->isB2eAvailable())
		{
			$this->includeNotAvailableTemplate();
			return;
		}

		parent::executeComponent();
	}

	private function isSafeFolderGroupingAllowed(): bool
	{
		return $this->type === self::SAFE_TYPE
			&& Storage::instance()->isB2eAvailable()
			&& \Bitrix\Sign\Config\Feature::instance()->isSafeFolderGroupingAllowed()
		;
	}

	public function filterFilteredItems(array $items): array
	{
		return $this->getIntersectFields($this->getAvailableFilterItems(), $items);
	}

	public function filterGridColumnsWithDefault(array $items): array
	{
		return $this->getIntersectFields($this->getDefaultGridColumnsList(), $items);
	}

	public function getIntersectFields(array $defaultItems, array $items): array
	{
		return array_replace_recursive(
			array_intersect_key($defaultItems, $items),
			$items
		);
	}

	protected function exec(): void
	{
		if (!$this->errors->isEmpty())
		{
			$this->showErrors();

			return;
		}

		try
		{
			$this->prepareResult();
		}
		catch (ObjectNotFoundException $e)
		{
			ShowError($e->getMessage());
			return;
		}

		if ($this->isExcelExportMode())
		{
			if ($this->safeExportErrorMessage !== null)
			{
				$this->redirectFromFailedSafeExport($this->safeExportErrorMessage);

				return;
			}

			$this->setTemplateName('excel');

			return;
		}

		$this->arResult['SAFE_EXPORT_ERROR_MESSAGE'] = $this->pullSafeExportErrorMessage();
	}

	private function redirectFromFailedSafeExport(string $message): void
	{
		Application::getInstance()->getSession()->set(self::SAFE_EXPORT_ERROR_SESSION_KEY, $message);

		LocalRedirect($this->getRequestedPage(
			delParams: [
				ExcelExporter::REQUEST_PARAM_NAME,
				'ncc',
				'selectedIds',
				'selectedFolderIds',
			],
		));
	}

	private function pullSafeExportErrorMessage(): ?string
	{
		$session = Application::getInstance()->getSession();
		$message = $session->get(self::SAFE_EXPORT_ERROR_SESSION_KEY);
		$session->remove(self::SAFE_EXPORT_ERROR_SESSION_KEY);

		return is_string($message) && $message !== '' ? $message : null;
	}

	public function getAction(): array
	{
		return match ($this->arParams['COMPONENT_TYPE'])
		{
			self::SAFE_TYPE => [
				AccessController::RULE_AND => [
					ActionDictionary::ACTION_B2E_MY_SAFE,
				],
			],
			default => parent::getAction(),
		};
	}

	protected function getCallbackAction(): array
	{
		return match ($this->arParams['COMPONENT_TYPE'])
		{
			self::PERSONAL_TYPE => [
				fn() => (int)CurrentUser::get()->getId() !== (int)$this->arParams['ENTITY_ID'],
			],
			self::DOCUMENT_TYPE => [
				function() {
					$document = $this->documentRepository->getById((int)$this->arParams['ENTITY_ID']);

					if (!$document)
					{
						return true;
					}

					return !$this->accessController->checkByItem(
						ActionDictionary::ACTION_B2E_DOCUMENT_READ,
						$document,
					);
				},
			],
			default => parent::getCallbackAction(),
		};
	}

	/**
	 * @throws ObjectNotFoundException
	 */
	private function prepareResult(): void
	{
		$this->prepareComponentParams();
		$this->prepareNavigationParams();
		$this->prepareFilters();
		$this->prepareGridParams();
		$this->prepareBannerParams();
		$this->prepareData();
		$this->prepareStub();
		$this->prepareEvents();
	}

	private function prepareComponentParams(): void
	{
		$this->type = mb_strtolower($this->arParams['COMPONENT_TYPE']);
		if (
			!in_array($this->type, [
				self::PERSONAL_TYPE,
				self::DOCUMENT_TYPE,
				self::SAFE_TYPE,
				self::CURRENT_TYPE,
			], true)
		)
		{
			$this->addError('Wrong component type', 'You use wrong component type');
		}

		$this->arResult['GRID_TYPE'] = $this->type;

		$this->entityId = isset($this->arParams['ENTITY_ID']) ? (int)$this->arParams['ENTITY_ID'] : null;
		if ($this->entityId === 0 && $this->type !== self::SAFE_TYPE)
		{
			$this->addError('Wrong entity id', 'You use wrong entity id');
		}

		$this->arResult['IS_SHOW_TITLE'] = true;
		$this->arResult['TITLE'] = match ($this->type)
		{
			self::PERSONAL_TYPE => Loc::getMessage('SIGN_PERSONAL_DOCUMENT_LIST_TITLE'),
			self::DOCUMENT_TYPE => Loc::getMessage('SIGN_DOCUMENT_DOCUMENT_LIST_TITLE'),
			self::SAFE_TYPE => $this->getSafeGridTitle(),
			self::CURRENT_TYPE => Loc::getMessage('SIGN_CURRENT_DOCUMENT_LIST_TITLE'),
		};

		$this->arResult['IS_SHOW_RESULT_STATUS_BUTTON'] = false;

		if ($this->type === self::DOCUMENT_TYPE)
		{
			$this->arResult['IS_SHOW_RESULT_STATUS_BUTTON'] = true;
		}

		$this->arResult['IS_SHOW_TOOLBAR_FILTER'] = true;
		if ($this->type === self::CURRENT_TYPE)
		{
			$this->arResult['IS_SHOW_TOOLBAR_FILTER'] = false;
		}

		// Server-fed flag consumed by the safe grid template to show the "Create folder" button.
		$this->arResult['CAN_CREATE_SAFE_FOLDER'] = $this->isSafeFolderGroupingAllowed()
			&& $this->safeAccessService->hasAccessToCreate();
	}

	private function prepareGridParams(): void
	{
		$this->arResult['GRID_ID'] = match ($this->type)
		{
			self::DOCUMENT_TYPE => self::DOCUMENT_DOCUMENT_GRID_ID,
			self::PERSONAL_TYPE => self::PERSONAL_DOCUMENT_GRID_ID,
			self::SAFE_TYPE => $this->isInsideSafeFolder()
				? self::SAFE_FOLDER_DOCUMENT_GRID_ID
				: self::SAFE_DOCUMENT_GRID_ID,
			self::CURRENT_TYPE => self::CURRENT_DOCUMENT_GRID_ID,
			default => self::DEFAULT_GRID_ID
		};
		$this->arResult['COLUMNS'] = $this->getGridColumns();
		$this->arResult['DOCUMENT_RESULT_FILE_DOWNLOAD_URL_TEMPLATE'] ??= self::DEFAULT_RESULT_FILE_DOWNLOAD_URL_TEMPLATE;
		$this->arResult['DOCUMENT_RESULT_FILE_DOWNLOAD_URL_TEMPLATE_HASH_KEY'] ??=
			self::DEFAULT_RESULT_FILE_DOWNLOAD_URL_TEMPLATE_HASH_KEY;
		$this->arResult['IS_EXCEL_EXPORT_AVAILABLE'] = $this->isExportableType();
		$this->arResult['IS_EXCEL_EXPORT_MODE'] = $this->isExcelExportMode();
		$this->arResult['VISIBLE_COLUMNS_FOR_EXCEL'] = $this->getVisibleColumnsForExcel();
	}

	private function isExcelExportMode(): bool
	{
		return $this->isExportableType()
			&& $this->getRequest(ExcelExporter::REQUEST_PARAM_NAME) === ExcelExporter::REQUEST_PARAM_VALUE;
	}

	/**
	 * Component types for which the excel export path is implemented (document and safe). The
	 * personal/current grids have no excel projection and stay out of the export mode.
	 */
	private function isExportableType(): bool
	{
		return in_array($this->type, [self::DOCUMENT_TYPE, self::SAFE_TYPE], true);
	}

	private function getDefaultGridColumnsList(): array
	{
		return [
			'id' => [
				'id' => 'ID',
				'name' => Loc::getMessage('SIGN_DOCUMENT_LIST_COLUMN_NAME_ID'),
				'editable' => false,
				'type' => Grid\Types::GRID_INT,
				'gridSort' => 0,
			],
			'title' => [
				'id' => 'TITLE',
				'name' => Loc::getMessage('SIGN_DOCUMENT_LIST_COLUMN_NAME_TITLE'),
				'default' => false,
				'editable' => false,
				'gridSort' => 0,
				'resizeable' => true,
				'width' => 600,
			],
			'member' => [
				'id' => 'MEMBER',
				'name' => Loc::getMessage('SIGN_DOCUMENT_LIST_COLUMN_NAME_MEMBER'),
				'default' => false,
				'editable' => false,
				'gridSort' => 0,
			],
			'role' => [
				'id' => 'ROLE',
				'name' => Loc::getMessage('SIGN_DOCUMENT_LIST_COLUMN_NAME_ROLE'),
				'default' => false,
				'editable' => false,
				'gridSort' => 0,
			],
			'download' => [
				'id' => 'DOWNLOAD_DOCUMENT',
				'name' => Loc::getMessage('SIGN_DOCUMENT_LIST_COLUMN_NAME_DOWNLOAD_ACTION_MSGVER_1'),
				'default' => false,
				'editable' => false,
				'gridSort' => 0,
			],
			'initiator' => [
				'id' => 'INITIATOR',
				'name' => Loc::getMessage('SIGN_DOCUMENT_LIST_COLUMN_NAME_INITIATOR'),
				'default' => false,
				'editable' => false,
				'gridSort' => 0,
			],
			'company' => [
				'id' => 'COMPANY',
				'name' => Loc::getMessage('SIGN_DOCUMENT_LIST_COLUMN_NAME_COMPANY'),
				'default' => false,
				'editable' => false,
				'gridSort' => 0,
			],
			'dateSign' => [
				'id' => 'DATE_SIGN',
				'name' => Loc::getMessage('SIGN_DOCUMENT_LIST_COLUMN_NAME_SIGN_DATE'),
				'default' => false,
				'editable' => false,
				'gridSort' => 0,
			],
			'createdBy' => [
				'id' => 'CREATED_BY',
				'name' => Loc::getMessage('SIGN_DOCUMENT_LIST_COLUMN_NAME_CREATED_BY'),
				'default' => false,
				'editable' => false,
				'gridSort' => 0,
			],
			'memberStatus' => [
				'id' => 'MEMBER_STATUS',
				'name' => Loc::getMessage('SIGN_DOCUMENT_LIST_COLUMN_NAME_STATUS'),
				'default' => false,
				'editable' => false,
				'gridSort' => 0,
			],
			'action' => [
				'id' => 'ACTION',
				'name' => Loc::getMessage('SIGN_DOCUMENT_LIST_COLUMN_NAME_ACTION'),
				'default' => false,
				'editable' => false,
				'gridSort' => 0,
			],
			'dateCreate' => [
				'id' => 'DATE_CREATE',
				'name' => Loc::getMessage('SIGN_DOCUMENT_LIST_COLUMN_NAME_CREATE_DATE'),
				'default' => false,
				'editable' => false,
				'gridSort' => 0,
			],
		];
	}

	private function getGridColumnsListForPersonal(): array
	{
		return $this->filterGridColumnsWithDefault([
			'id' => [],
			'title' => [
				'default' => true,
			],
			'download' => [
				'default' => true,
				'gridSort' => 800,
			],
			'initiator' => [],
			'dateSign' => [
				'default' => true,
			],
			'createdBy' => [],
		]);
	}

	private function getGridColumnsListForDocument(): array
	{
		return $this->filterGridColumnsWithDefault([
			'id' => [],
			'member' => [
				'default' => true,
			],
			'role' => [
				'default' => true,
			],
			'memberStatus' => [
				'default' => true,
			],
			'action' => [
				'default' => true,
				'gridSort' => 800,
			],
			'dateSign' => [
				'gridSort' => 200
			],
		]);
	}

	private function getGridColumnsListForSafe(): array
	{
		$columns = [
			'id' => [],
			'title' => [
				'default' => true,
				'sort' => 'title',
//				'width' => 400,
			],
			'role' => [
				'default' => true,
			],
			'member' => [
				'default' => true,
			],
			'download' => [
				'default' => true,
				'gridSort' => 800,
			],
			'initiator' => [],
			'dateSign' => [
				'default' => true,
				'sort' => 'dateSign',
			],
			'createdBy' => [],
		];

		// DTO-01 (P5.T3): the dedicated "Company" column and the representative/sender role columns
		// (which carry the folder-row avatar stacks) ship with the folder grouping feature only, so the
		// legacy safe grid stays unchanged when the flag is off.
		if ($this->isSafeFolderGroupingAllowed())
		{
			$columns['initiator']['default'] = true;
			$columns['createdBy']['default'] = true;
			$columns['company'] = [
				'default' => true,
			];
		}

		$columns = $this->filterGridColumnsWithDefault($columns);

		[$activeField] = $this->getSafeDataOrder();

		return self::highlightActiveSafeSortColumns($columns, $activeField);
	}

	/**
	 * Highlights the safe-grid column that drives the resolved sort, mirroring
	 * sign.mysafe.
	 *
	 * The highlight follows the normalized sort (getSafeDataOrder), not the raw
	 * request tokens, so the header background and the actual row order can
	 * never disagree: a null field lights up nothing, otherwise only the column
	 * whose sort token maps to that field via SAFE_SORTABLE_COLUMNS is colored.
	 * Called only from the safe branch, so the other grid modes are unaffected.
	 *
	 * @param array<string, array> $columns
	 * @return array<string, array>
	 */
	private static function highlightActiveSafeSortColumns(array $columns, ?MySafeSortField $activeField): array
	{
		if ($activeField === null)
		{
			return $columns;
		}

		foreach ($columns as &$column)
		{
			$sortToken = $column['sort'] ?? null;
			if (is_string($sortToken) && (self::SAFE_SORTABLE_COLUMNS[$sortToken] ?? null) === $activeField)
			{
				$column['color'] = Color::BLUE;
			}
		}
		unset($column);

		return $columns;
	}

	private function getGridColumns(): array
	{
		if (isset($this->arResult['COLUMNS']))
		{
			return $this->arResult['COLUMNS'];
		}

		$gridColumns = match ($this->type) {
			self::PERSONAL_TYPE => $this->getGridColumnsListForPersonal(),
			self::DOCUMENT_TYPE => $this->getGridColumnsListForDocument(),
			self::SAFE_TYPE => $this->getGridColumnsListForSafe(),
			self::CURRENT_TYPE => $this->getGridColumnsListForCurrent(),
		};

		uasort(
			$gridColumns,
			static fn(array $columnA, array $columnB) => $columnA['gridSort'] <=> $columnB['gridSort']
		);

		return $gridColumns;
	}

	private function getGridOptions(): Grid\Options
	{
		return new Grid\Options($this->arResult["GRID_ID"]);
	}
	private function getPersonalMemberCollection(array $requestFilter): MemberCollection
	{
		return $this->memberRepository->listSignersByUserIdIsDone(
			$this->entityId,
			$this->getFilterForQuery($requestFilter),
			$this->getLimitForQuery(),
			$this->getOffsetForQuery()
		);
	}

	/**
	 * @throws ObjectNotFoundException
	 */
	private function getDocumentMemberCollection(array $requestFilter): MemberCollection
	{
		$filter = $this->getFilterForQuery($requestFilter);

		if (isset($requestFilter['ENTITY_ID']) && is_array($requestFilter['ENTITY_ID']))
		{
			$filter->where(
				\Bitrix\Main\ORM\Query\Query::filter()
					->logic('or')
					->where(
						\Bitrix\Main\ORM\Query\Query::filter()
							->logic('and')
							->where('ENTITY_TYPE', '=', EntityType::USER)
							->whereIn('ENTITY_ID', $this->prepareCollectionIds($requestFilter['ENTITY_ID']))
					)
					->where(
						\Bitrix\Main\ORM\Query\Query::filter()
							->logic('and')
							->where('ENTITY_TYPE', '=', EntityType::COMPANY)
							->where('ROLE', '=', $this->memberRepository->convertRoleToInt(Role::ASSIGNEE))
					)
			);
		}

		$document = $this->documentRepository->getById($this->entityId);
		if ($document === null)
		{
			throw new ObjectNotFoundException(
				Loc::getMessage('SIGN_DOCUMENT_LIST_DOC_NOT_FOUND', ['#DOC_ID#' => $this->entityId])
			);
		}

		$this->setResult('EXCEL_DOCUMENT_NAME', $this->getExcelFileName($this->getDocumentTitle($document)));

		if (isset($requestFilter['MEMBER_STATUS']) && is_array($requestFilter['MEMBER_STATUS']))
		{
			if (
				(
					in_array(MemberStatus::READY, $requestFilter['MEMBER_STATUS'], true)
					|| in_array(MemberStatus::PROCESSING, $requestFilter['MEMBER_STATUS'], true)
				)
				&& $document->status !== Type\DocumentStatus::STOPPED
			)
			{
				$requestFilter['MEMBER_STATUS'][] = MemberStatus::STOPPABLE_READY;
			}

			$signed = array_filter($requestFilter['MEMBER_STATUS'], static fn($item) => in_array($item, MemberStatus::getAll(), true));

			if (
				$document->status === Type\DocumentStatus::STOPPED
				&& in_array(MemberStatus::STOPPED, $requestFilter['MEMBER_STATUS'], true)
			)
			{
				$signed = [...$signed, MemberStatus::STOPPED, MemberStatus::WAIT, MemberStatus::STOPPABLE_READY, MemberStatus::PROCESSING];
			}

			$roleCondition = array_filter(
				$filter->getConditions(),
				static fn(ConditionTree|Condition $item) => $item instanceof Condition && $item->getColumn() === 'ROLE'
			);
			if (empty($roleCondition))
			{
				$filter->where('ROLE', '=', $this->memberRepository->convertRoleToInt(Role::SIGNER));
			}
			$filter->whereIn('SIGNED', array_unique($signed));
		}

		$isExcelExportMode = $this->isExcelExportMode();
		$limit = $this->getMaxB2eDocumentMembers();
		$offset = null;
		if (!$isExcelExportMode)
		{
			$limit = $this->getLimitForQuery() + 1;
			$offset = ($this->getNavigation()->getCurrentPage() - 1) * $this->getNavigation()->getPageSize();
		}

		$memberCollection = $this->memberRepository->listB2eMemberByDocumentId(
			(int)$this->entityId,
			$filter,
			self::ROLE_RELEVANCE,
			self::STATUS_RELEVANCE,
			$limit,
			$offset,
		);

		$this->arResult['COUNTER_ITEMS'] = $this->getCounterItems($document, $filter);
		$items = $memberCollection->toArray();

		array_walk($items, static function (Item\Member $member) use ($document) {
			if ($member->role === Role::ASSIGNEE)
			{
				$member->entityId = $document->representativeId;
				$member->entityType = EntityType::USER;
			}
		});

		if (isset($requestFilter['ENTITY_ID']) && is_array($requestFilter['ENTITY_ID']))
		{
			$items = array_filter($items, static function (Item\Member $member) use ($requestFilter) {
				return in_array(
					$member->entityId,
					array_map(static fn($id) => (int)$id, $requestFilter['ENTITY_ID']),
					true
				);
			});
		}
		// get N+1 to understand there are any more elements in the database after that,
		// and we will display N elements.
		if (!$isExcelExportMode && $memberCollection->count() === $limit)
		{
			array_pop($items);
		}
		$resultCollection = new MemberCollection(...$items);
		$resultCollection->setQueryTotal($memberCollection->count() + ($isExcelExportMode ? 0 : $this->getNavigation()->getOffset()));
		$this->arResult['SHOW_TOTAL_COUNTER'] = false;

		return $resultCollection;
	}

	private function getSafeMemberCollection(array $requestFilter): MemberCollection
	{
		// NORMATIVE ALG-02: inside a folder the whole level is gated by the folder read
		// permission — deny before touching the query if the folder is not accessible.
		if ($this->isSafeFolderGroupingAllowed() && $this->getCurrentSafeFolderId() > 0)
		{
			if (!$this->safeListService->canAccessLevel(
				$this->accessController->getUser(),
				$this->getCurrentSafeFolderId(),
			))
			{
				return new MemberCollection();
			}
		}

		[$orderField, $orderDirection] = $this->getSafeDataOrder();

		return $this->memberRepository->listB2eMembersWithResultFilesForMySafe(
			$this->getFilterForQuery($requestFilter),
			$this->getLimitForQuery(),
			$this->getOffsetForQuery(),
			$orderField,
			$orderDirection,
		);
	}

	/**
	 * Resolves the safe-grid sort request into a typed field and direction.
	 *
	 * The untrusted grid sorting is normalized through the SAFE_SORTABLE_COLUMNS
	 * allowlist, so an injected column or direction can never reach the ORDER BY
	 * clause. Only the safe branch calls this method.
	 *
	 * @return array{0: ?MySafeSortField, 1: Order}
	 */
	private function getSafeDataOrder(): array
	{
		return self::resolveSafeDataOrder($this->getSafeSortRequest());
	}

	/**
	 * Returns the raw safe-grid sort request, read once per render.
	 *
	 * Memoized so the safe branch (columns highlight + data order) reads the
	 * grid options a single time. The '?? []' fallback is defensive and stays
	 * even though getSorting() normally returns the 'sort' key.
	 *
	 * @return array<string, mixed>
	 */
	private function getSafeSortRequest(): array
	{
		return $this->safeSortRequest ??= ($this->getGridOptions()->getSorting()['sort'] ?? []);
	}

	/**
	 * Normalizes a raw grid sort request into a typed [field, direction] pair.
	 *
	 * Entries are scanned in order and invalid ones (unknown column or an
	 * unparseable direction) are skipped, so the first *valid* entry wins - the
	 * first entry is not required to be valid, scanning continues past it. This
	 * yields the single-column invariant. A structurally broken request (not an
	 * array) or the absence of any valid entry falls back to the provider
	 * default SAFE_DEFAULT_DATA_ORDER ([null, Order::Desc]).
	 *
	 * @return array{0: ?MySafeSortField, 1: Order}
	 */
	private static function resolveSafeDataOrder(mixed $sort): array
	{
		if (!is_array($sort))
		{
			return self::SAFE_DEFAULT_DATA_ORDER;
		}

		foreach ($sort as $column => $direction)
		{
			$field = self::SAFE_SORTABLE_COLUMNS[$column] ?? null;
			if ($field === null)
			{
				continue;
			}

			$order = is_string($direction) ? Order::tryFrom(mb_strtoupper($direction)) : null;
			if ($order === null)
			{
				continue;
			}

			// first valid entry wins: only one column may drive the order
			return [$field, $order];
		}

		return self::SAFE_DEFAULT_DATA_ORDER;
	}

	private function getFilterForQuery(array $requestFilter, bool $applySafeAcl = true): ConditionTree
	{
		$filter = Bitrix\Main\ORM\Query\Query::filter();

		if (isset($requestFilter['DATE_SIGN_from']) && $requestFilter['DATE_SIGN_from'])
		{
			$filter->where('DATE_SIGN', '>=', new \Bitrix\Main\Type\DateTime($requestFilter['DATE_SIGN_from']));
		}
		if (isset($requestFilter['DATE_SIGN_to']) && $requestFilter['DATE_SIGN_to'])
		{
			$filter->where('DATE_SIGN', '<=', new \Bitrix\Main\Type\DateTime($requestFilter['DATE_SIGN_to']));
		}
		if (isset($requestFilter['DATE_CREATE_from']) && $requestFilter['DATE_CREATE_from'])
		{
			$filter->where('DATE_CREATE', '>=', new \Bitrix\Main\Type\DateTime($requestFilter['DATE_CREATE_from']));
		}
		if (isset($requestFilter['DATE_CREATE_to']) && $requestFilter['DATE_CREATE_to'])
		{
			$filter->where('DATE_CREATE', '<=', new \Bitrix\Main\Type\DateTime($requestFilter['DATE_CREATE_to']));
		}

		if (isset($requestFilter['SIGNED']) && is_array($requestFilter['SIGNED']))
		{
			$filter->whereIn('SIGNED', array_filter($requestFilter['SIGNED'], static fn($item) => in_array($item, MemberStatus::getAll(), true)));
		}

		if (
			isset($requestFilter['MEMBER_STATUS'])
			&& is_array($requestFilter['MEMBER_STATUS'])
			&& in_array($this->type, [self::PERSONAL_TYPE, self::SAFE_TYPE], true)
		)
		{
			if (
				in_array(MemberStatus::READY, $requestFilter['MEMBER_STATUS'], true)
				|| in_array(MemberStatus::PROCESSING, $requestFilter['MEMBER_STATUS'], true )
			)
			{
				$requestFilter['MEMBER_STATUS'][] = MemberStatus::STOPPABLE_READY;
			}

			$filter->whereIn('SIGNED', array_filter($requestFilter['MEMBER_STATUS'], static fn($item) => in_array($item, MemberStatus::getAll(), true)));
			$filter->where('ROLE', '=',  $this->memberRepository->convertRoleToInt(Role::SIGNER));
		}

		if (isset($requestFilter['DOCUMENT_NAME']) && $requestFilter['DOCUMENT_NAME'] !== '')
		{
			$filter->where((\Bitrix\Main\ORM\Query\Query::filter())
				->logic('OR')
				->whereLike('DOCUMENT.TITLE', '%' . (string)$requestFilter['DOCUMENT_NAME'] . '%')
				->whereLike('DOCUMENT.EXTERNAL_ID', '%' . (string)$requestFilter['DOCUMENT_NAME'] . '%')
			);
		}

		if (isset($requestFilter['REPRESENTATIVE']) && is_array($requestFilter['REPRESENTATIVE']))
		{
			$filter->whereIn('DOCUMENT.REPRESENTATIVE_ID', $this->prepareCollectionIds($requestFilter['REPRESENTATIVE']));
		}

		if (isset($requestFilter['CREATED_BY_ID']) && is_array($requestFilter['CREATED_BY_ID']))
		{
			$filter->whereIn('DOCUMENT.CREATED_BY_ID', $this->prepareCollectionIds($requestFilter['CREATED_BY_ID']));
		}

		if (
			isset($requestFilter['ENTITY_ID'])
			&& is_array($requestFilter['ENTITY_ID'])
			&& in_array($this->type, [self::PERSONAL_TYPE, self::SAFE_TYPE], true)
		)
		{
			$entityIds = $this->prepareCollectionIds($requestFilter['ENTITY_ID']);
			$filter->where((\Bitrix\Main\ORM\Query\Query::filter())
				->logic('or')
				// filter signers
				->where(\Bitrix\Main\ORM\Query\Query::filter()
					->whereIn('ENTITY_ID', $entityIds)
					->where('ENTITY_TYPE', '=', EntityType::USER)
				)
				// filter assignee
				->where(\Bitrix\Main\ORM\Query\Query::filter()
					->where('ROLE', '=', $this->memberRepository->convertRoleToInt(Role::ASSIGNEE))
					->whereIn("DOCUMENT.REPRESENTATIVE_ID", $entityIds)
				)
				// filter assignee of employee-initiated documents by signer (document creator)
				->where(\Bitrix\Main\ORM\Query\Query::filter()
					->where('DOCUMENT.INITIATED_BY_TYPE', '=', Type\Document\InitiatedByType::EMPLOYEE->toInt())
					->whereIn('DOCUMENT.CREATED_BY_ID', $entityIds)
					->where('ROLE', '=', $this->memberRepository->convertRoleToInt(Role::ASSIGNEE))
				)
			);
		}

		if (isset($requestFilter['ROLE']) && is_array($requestFilter['ROLE']))
		{
			$roles = array_map(
				fn(string $role) => $this->memberRepository->convertRoleToInt($role),
				array_filter($requestFilter['ROLE'], static fn ($role) => in_array($role, Role::getAll(), true))
			);
			$filter->whereIn('ROLE', $roles);
		}

		if ($this->type === self::SAFE_TYPE && $applySafeAcl)
		{
			// NORMATIVE ALG-02: the safe list ACL comes from the shared ListService, keeping the
			// grid and the MySafe REST surface equivalent. With folder grouping the filter is the
			// current level (root: folderless owner-scoped documents; a folder: FOLDER_ID match);
			// without it, the legacy documents owner-scope is used.
			$user = $this->accessController->getUser();
			if ($this->isSafeFolderGroupingAllowed())
			{
				$filter->where(
					$this->safeListService->buildLevelDocumentFilter($user, $this->getCurrentSafeFolderId())
				);
			}
			else
			{
				$filter->where($this->safeListService->buildOwnerScopeFilter($user));
			}
		}

		if (isset($requestFilter['COMPANY']))
		{
			$this->prepareFilterForCompanyFilterField($filter, $requestFilter);
		}

		$filter->where('ENTITY_TYPE', '!=', EntityType::ROLE);

		return $filter;
	}

	/**
	 * Current safe level (DTO-01): the `folderId` request param drives folder navigation. 0 (or an
	 * absent/invalid value) means the root level; a positive id means the content of that folder.
	 */
	private function getCurrentSafeFolderId(): int
	{
		$folderId = (int)$this->getRequest('folderId');

		return $folderId > 0 ? $folderId : 0;
	}

	/**
	 * Whether the safe grid renders the content of a folder rather than the root level. Used to pick a
	 * folder-scoped grid id so folder and root sorting stay independent (the filter remains shared).
	 */
	private function isInsideSafeFolder(): bool
	{
		return $this->isSafeFolderGroupingAllowed() && $this->getCurrentSafeFolderId() > 0;
	}

	/**
	 * Safe grid page/slider title. Inside a folder (folder grouping on, folderId > 0) the title is the
	 * folder name — mirroring the templates grid, where the folder-content slider shows the folder
	 * title instead of the section title. Gated on level access so an inaccessible folderId can never
	 * leak the folder name.
	 */
	private function getSafeGridTitle(): string
	{
		$defaultTitle = (string)Loc::getMessage('SIGN_SAFE_DOCUMENT_LIST_TITLE');
		if (!$this->isSafeFolderGroupingAllowed())
		{
			return $defaultTitle;
		}

		$folderId = $this->getCurrentSafeFolderId();
		if ($folderId <= 0)
		{
			return $defaultTitle;
		}

		if (!$this->safeListService->canAccessLevel($this->accessController->getUser(), $folderId))
		{
			return $defaultTitle;
		}

		$folder = Container::instance()->getSafeFolderRepository()->getById($folderId);

		return $folder?->title ?? $defaultTitle;
	}

	private function getOffsetForQuery(): int
	{
		return (int)$this->getNavigation()->getOffset();
	}

	private function getLimitForQuery(): int
	{
		return (int)$this->getNavigation()->getLimit();
	}

	/**
	 * Maximum number of B2E members allowed per document.
	 * Used as a bounded limit for the excel export query so it never runs an unlimited select.
	 */
	private function getMaxB2eDocumentMembers(): int
	{
		return self::MAX_B2E_SIGNER_MEMBERS
			+ self::MAX_B2E_REVIEWERS
			+ self::MAX_B2E_FIRST_SIGNER
			+ self::MAX_B2E_EDITOR;
	}

	/**
	 * @throws ObjectNotFoundException
	 */
	private function prepareData(): void
	{
		$filterOptions = $this->getFilterOptions();
		$requestFilter = $this->getRequestFilters($filterOptions);

		// NORMATIVE ALG-01: the safe excel export is a flat, unpaginated projection of the visible
		// records (no folder rows) and fully owns its data path - it bypasses the grid pagination and
		// the folder-row grouping below.
		if ($this->type === self::SAFE_TYPE && $this->isExcelExportMode())
		{
			$this->prepareSafeExportData($filterOptions, $requestFilter);

			return;
		}

		// NORMATIVE ALG-02 / DTO-01: with folder grouping the safe grid mixes folder rows and member
		// rows into a single page budget (P5.T1), gates folders by the active filter (P5.T2) and
		// carries per-folder aggregates (P5.T3). This path fully owns the safe grid data.
		if ($this->type === self::SAFE_TYPE && $this->isSafeFolderGroupingAllowed())
		{
			$this->prepareSafeGroupedData($filterOptions, $requestFilter);

			return;
		}

		$memberCollection = match ($this->type)
		{
			self::PERSONAL_TYPE => $this->getPersonalMemberCollection($requestFilter),
			self::DOCUMENT_TYPE => $this->getDocumentMemberCollection($requestFilter),
			self::SAFE_TYPE => $this->getSafeMemberCollection($requestFilter),
			self::CURRENT_TYPE => $this->getCurrentMemberCollection(),
		};

		$documentCollection = $this->getDocumentsByMembers($memberCollection);

		$this->arResult['DOCUMENTS'] = $this->getDocumentsDataForGrid($memberCollection, $documentCollection);
		$this->arResult['SAFE_FOLDER_ID'] = 0;
		$this->arResult['TOTAL_COUNT'] = $memberCollection->getQueryTotal() ?? 0;
		$this->getNavigation()->setRecordCount($this->arResult['TOTAL_COUNT']);
	}

	/**
	 * Safe grid data with folder grouping (DTO-01). Builds one page that lists folder rows first,
	 * then member rows, from a shared page budget (P5.T1); folders are gated by the active filter
	 * (P5.T2); each folder row carries its aggregates (P5.T3). The document owner-scope / level
	 * filter is delegated to the shared ListService so the grid and the MySafe REST stay equivalent.
	 */
	private function prepareSafeGroupedData(Filter\Options $filterOptions, array $requestFilter): void
	{
		$user = $this->accessController->getUser();
		$folderId = $this->getCurrentSafeFolderId();
		[$orderField, $orderDirection] = $this->getSafeDataOrder();
		$folderTitleOrder = $orderField === MySafeSortField::DocumentTitle ? $orderDirection : null;

		// #7: fold the grid quick-search string into the level filter (title/external id), so the
		// search actually narrows the members and gates the folders instead of doing nothing.
		$requestFilter = $this->applySafeSearchString($filterOptions, $requestFilter);

		// Inside a folder the whole level is gated by the folder read permission.
		if ($folderId > 0 && !$this->safeListService->canAccessLevel($user, $folderId))
		{
			$this->setEmptySafeResult($folderId);

			return;
		}

		$isFolderMemberFilterActive = $folderId === 0 && $this->isSafeFilterActive($requestFilter);
		$folderMemberFilter = null;
		// P5.T2: at the root, an active filter hides folders with no accessible matching member.
		if ($isFolderMemberFilterActive)
		{
			$folderFilter = $this->getFilterForQuery($requestFilter, applySafeAcl: false);
			$readableFolderScope = $this->safeListService->buildReadableFolderScopeFilter($user);
			if ($readableFolderScope !== null)
			{
				$folderMemberFilter = $folderFilter->where($readableFolderScope);
			}
		}
		$folderCount = match (true)
		{
			$folderMemberFilter !== null => $this->memberRepository->countFolderIdsWithMembersForMySafe(
				$folderMemberFilter,
			),
			$isFolderMemberFilterActive => 0,
			default => $this->safeListService->countLevelFolders($user, $folderId),
		};

		$memberFilter = $this->getFilterForQuery($requestFilter);
		$memberTotal = $this->memberRepository->countB2eMembersForMySafe($memberFilter);

		$slice = $this->safeListService->calculatePageSlice(
			$folderCount,
			$memberTotal,
			(int)$this->getNavigation()->getCurrentPage(),
			(int)$this->arResult['PAGE_SIZE'],
		);

		if ($isFolderMemberFilterActive)
		{
			$folderIdsWithMembers = $folderMemberFilter === null || $slice['folderLimit'] === 0
				? []
				: $this->memberRepository->listFolderIdsWithMembersForMySafe(
					$folderMemberFilter,
					$slice['folderLimit'],
					$slice['folderOffset'],
					$folderTitleOrder,
				)
			;
			$foldersSlice = $this->safeListService->getLevelFoldersPage(
				$user,
				$folderId,
				$slice['folderLimit'],
				0,
				folderIds: $folderIdsWithMembers,
				titleOrder: $folderTitleOrder,
			);
		}
		else
		{
			$foldersSlice = $this->safeListService->getLevelFoldersPage(
				$user,
				$folderId,
				$slice['folderLimit'],
				$slice['folderOffset'],
				titleOrder: $folderTitleOrder,
			);
		}

		$memberCollection = new MemberCollection();
		if ($slice['memberLimit'] > 0)
		{
			$memberCollection = $this->memberRepository->listB2eMembersWithResultFilesForMySafe(
				$memberFilter,
				$slice['memberLimit'],
				$slice['memberOffset'],
				$orderField,
				$orderDirection,
				countTotal: false,
			);
		}
		$documentCollection = $this->getDocumentsByMembers($memberCollection);

		// DTO-01: folder rows (with aggregates) precede the document rows on the page.
		$this->arResult['DOCUMENTS'] = array_merge(
			$this->getSafeFolderAggregateRows($foldersSlice),
			$this->getDocumentsDataForGrid($memberCollection, $documentCollection),
		);
		$this->arResult['SAFE_FOLDER_ID'] = $folderId;
		$this->arResult['TOTAL_COUNT'] = $slice['total'];
		$this->getNavigation()->setRecordCount($slice['total']);
	}

	/**
	 * Safe excel export data (NORMATIVE ALG-01). Builds the flat, unpaginated set of member rows the
	 * user may see at the current level: at the root the accessible folders are expanded into their
	 * documents (plus the folderless bucket), inside a folder only that folder's documents; the active
	 * filter/search and an optional selection of member ids narrow the set further. Folder rows are
	 * never exported. The set is counted first: an empty set, a set larger than SAFE_EXPORT_LIMIT, a
	 * level the user may not access, or a build failure all set an error message and emit no file.
	 */
	private function prepareSafeExportData(Filter\Options $filterOptions, array $requestFilter): void
	{
		$user = $this->accessController->getUser();
		$level = $this->isSafeFolderGroupingAllowed() ? $this->getCurrentSafeFolderId() : 0;

		// ALG-01 access gate. With folder grouping a level may be a folder gated by its read permission;
		// deny before touching the query if the folder is not accessible.
		if ($this->isSafeFolderGroupingAllowed() && !$this->safeListService->canAccessLevel($user, $level))
		{
			$this->safeExportErrorMessage = Loc::getMessage('SIGN_DOCUMENT_LIST_SAFE_EXPORT_ACCESS_DENIED');
			$this->setEmptySafeResult($level);

			return;
		}

		try
		{
			// Fold the grid quick-search string into the level filter, mirroring the grid.
			$requestFilter = $this->applySafeSearchString($filterOptions, $requestFilter);

			// ALG-01: aclFilter AND active filter/search AND (optional) selected member ids. The safe
			// ACL of getFilterForQuery() is skipped here: the export uses the flat/level scope below,
			// which differs from the grid level scope (the root expands folders instead of listing them).
			$filter = $this->getFilterForQuery($requestFilter, applySafeAcl: false);
			$filter->where($this->buildSafeExportAclFilter($user, $level));

			// Selection scope (Q-1): the export narrows to the checked rows. Documents are
			// selected by their member id (ID); folders by their id (FOLDER_ID of the members
			// inside them). A mix combines both with OR, so selecting a folder exports its
			// content and a mixed selection exports the folder content plus the chosen
			// documents. The ACL filter above stays ANDed on top, so nothing outside the
			// visible/accessible level can leak in.
			$selectedIds = $this->getSafeExportSelectedIds();
			$selectedFolderIds = $this->getSafeExportSelectedFolderIds();
			if (!empty($selectedIds) || !empty($selectedFolderIds))
			{
				$selectionFilter = Query::filter()->logic('or');
				if (!empty($selectedIds))
				{
					$selectionFilter->whereIn('ID', $selectedIds);
				}
				if (!empty($selectedFolderIds))
				{
					$selectionFilter->whereIn('FOLDER_ID', $selectedFolderIds);
				}
				$filter->where($selectionFilter);
			}

			$total = $this->memberRepository->countB2eMembersForMySafeUpTo(
				$filter,
				self::SAFE_EXPORT_LIMIT + 1,
			);
			if ($total === 0)
			{
				$this->safeExportErrorMessage = Loc::getMessage('SIGN_DOCUMENT_LIST_SAFE_EXPORT_NO_DATA');
				$this->setEmptySafeResult($level);

				return;
			}

			if ($total > self::SAFE_EXPORT_LIMIT)
			{
				$this->safeExportErrorMessage = Loc::getMessage('SIGN_DOCUMENT_LIST_SAFE_EXPORT_LIMIT_EXCEEDED');
				$this->setEmptySafeResult($level);

				return;
			}

			// The total is already known from the gate above; skip the list method's own COUNT.
			$memberCollection = $this->memberRepository->listB2eMembersWithResultFilesForMySafeExport(
				$filter,
				self::SAFE_EXPORT_LIMIT,
			);
			$documentCollection = $this->getDocumentsByMembers($memberCollection, isSafeExport: true);

			// Batch-resolve every folder title of the set (plus the current level) in one query, feeding
			// both the per-row "Folder" column and the file name below.
			$this->safeExportFolderTitles = $this->resolveSafeExportFolderTitles($memberCollection, $level);
			$this->setResult(
				'EXCEL_DOCUMENT_NAME',
				$this->getExcelFileName($this->getSafeExportBaseName($level)),
			);

			$this->arResult['DOCUMENTS'] = $this->getDocumentsDataForGrid(
				$memberCollection,
				$documentCollection,
				isSafeExport: true,
			);
			$this->arResult['SAFE_FOLDER_ID'] = $level;
			$this->arResult['TOTAL_COUNT'] = $total;
			$this->getNavigation()->setRecordCount($total);
		}
		catch (\Throwable $e)
		{
			// Log the real cause; the user still gets a generic error and no partial file.
			Container::instance()->getLogger('SafeExport')->error(
				'Failed to prepare safe export data for level {level}: {errorMessage}',
				[
					'level' => $level,
					'errorMessage' => $e->getMessage(),
				],
			);
			$this->safeExportErrorMessage = Loc::getMessage('SIGN_DOCUMENT_LIST_SAFE_EXPORT_ERROR');
			$this->setEmptySafeResult($level);
		}
	}

	/**
	 * Batch-resolves the titles of every folder referenced by the export set plus the current level
	 * (SafeFolderRepository::getTitlesByIds, one query). Feeds the per-row "Folder" column and the
	 * file name; a folderless record has no folder id and is not looked up.
	 *
	 * @return array<int, string> folderId => title
	 */
	private function resolveSafeExportFolderTitles(MemberCollection $members, int $level): array
	{
		$folderIds = [];
		foreach ($members as $member)
		{
			if ((int)$member->folderId > 0)
			{
				$folderIds[] = (int)$member->folderId;
			}
		}
		if ($level > 0)
		{
			$folderIds[] = $level;
		}

		if (empty($folderIds))
		{
			return [];
		}

		return Container::instance()
			->getSafeFolderRepository()
			->getTitlesByIds(array_values(array_unique($folderIds)))
		;
	}

	/**
	 * Base file name for the safe export (extension appended by getExcelFileName). At the root the
	 * localized "Company safe" caption, inside a folder the folder title, each followed by the local
	 * portal date and time ("{YYYY-MM-DD} {HH-mm}", the time colon replaced with a dash).
	 */
	private function getSafeExportBaseName(int $level): string
	{
		$now = new \Bitrix\Main\Type\DateTime();
		$now->setDefaultTimeZone();
		$dateTimePart = $now->format('Y-m-d H-i');

		$baseTitle = $level > 0 ? (string)($this->safeExportFolderTitles[$level] ?? '') : '';
		if ($baseTitle === '')
		{
			$baseTitle = Loc::getMessage('SIGN_DOCUMENT_LIST_SAFE_EXPORT_FILE_NAME');
		}

		return $baseTitle . ' ' . $dateTimePart;
	}

	/**
	 * ALG-01 export scope (single source of truth: ListService). Inside a folder: that folder's
	 * documents; at the root with folder grouping: the flat expansion of accessible folders plus the
	 * folderless bucket; without folder grouping: the legacy documents owner-scope.
	 */
	private function buildSafeExportAclFilter(\Bitrix\Sign\Access\Model\UserModel $user, int $level): ConditionTree
	{
		if (!$this->isSafeFolderGroupingAllowed())
		{
			return $this->safeListService->buildOwnerScopeFilter($user);
		}

		if ($level > 0)
		{
			return $this->safeListService->buildLevelDocumentFilter($user, $level);
		}

		return $this->safeListService->buildFlatDocumentFilter($user);
	}

	/**
	 * Selected member ids for the export (Q-1). Read from the request (query or body), accepting an
	 * array or a comma-separated string; an empty selection means "export the whole level".
	 *
	 * @return array<int>
	 */
	private function getSafeExportSelectedIds(): array
	{
		$raw = $this->getRequest('selectedIds');
		if (is_string($raw))
		{
			$raw = $raw === '' ? [] : explode(',', $raw);
		}

		if (!is_array($raw))
		{
			return [];
		}

		return $this->capSafeExportSelection($this->prepareCollectionIds($raw));
	}

	/**
	 * Selected folder ids for the export (Q-1). Read from the request (query or body),
	 * accepting an array or a comma-separated string. The grid identifies folder rows with
	 * a `folder-{N}` row id; the frontend extracts the numeric {N} before sending. An empty
	 * selection means "no folder selected" and behaves as if the field was absent.
	 *
	 * @return array<int>
	 */
	private function getSafeExportSelectedFolderIds(): array
	{
		$raw = $this->getRequest('selectedFolderIds');
		if (is_string($raw))
		{
			$raw = $raw === '' ? [] : explode(',', $raw);
		}

		if (!is_array($raw))
		{
			return [];
		}

		return $this->capSafeExportSelection($this->prepareCollectionIds($raw));
	}

	/**
	 * Bounds a selection id list to SAFE_EXPORT_LIMIT + 1 before it reaches the query. A crafted
	 * request with tens of thousands of ids would otherwise inflate the IN condition and its COUNT;
	 * the extra +1 keeps an over-limit selection over the gate so it still fails with "refine filter".
	 *
	 * @param array<int> $ids
	 * @return array<int>
	 */
	private function capSafeExportSelection(array $ids): array
	{
		return array_slice($ids, 0, self::SAFE_EXPORT_LIMIT + 1);
	}

	private function setEmptySafeResult(int $folderId): void
	{
		$this->arResult['DOCUMENTS'] = [];
		$this->arResult['SAFE_FOLDER_ID'] = $folderId;
		$this->arResult['TOTAL_COUNT'] = 0;
		$this->getNavigation()->setRecordCount(0);
	}

	/**
	 * Folds the grid quick-search string into the DOCUMENT_NAME filter field (#7). The explicit
	 * "document name" filter field, when set, takes precedence over the free-text search box.
	 */
	private function applySafeSearchString(Filter\Options $filterOptions, array $requestFilter): array
	{
		$searchString = trim((string)$filterOptions->getSearchString());
		if (
			$searchString !== ''
			&& (!isset($requestFilter['DOCUMENT_NAME']) || (string)$requestFilter['DOCUMENT_NAME'] === '')
		)
		{
			$requestFilter['DOCUMENT_NAME'] = $searchString;
		}

		return $requestFilter;
	}

	/**
	 * Whether the current safe request carries an active filter (P5.T2). The quick-search string is
	 * already folded into DOCUMENT_NAME by applySafeSearchString().
	 */
	private function isSafeFilterActive(array $requestFilter): bool
	{
		if (isset($requestFilter['DOCUMENT_NAME']) && (string)$requestFilter['DOCUMENT_NAME'] !== '')
		{
			return true;
		}

		foreach (['ROLE', 'ENTITY_ID', 'REPRESENTATIVE', 'CREATED_BY_ID', 'COMPANY', 'MEMBER_STATUS', 'SIGNED'] as $key)
		{
			if (isset($requestFilter[$key]) && is_array($requestFilter[$key]) && $requestFilter[$key] !== [])
			{
				return true;
			}
		}

		foreach (['DATE_SIGN_from', 'DATE_SIGN_to', 'DATE_CREATE_from', 'DATE_CREATE_to'] as $key)
		{
			if (isset($requestFilter[$key]) && $requestFilter[$key] !== '')
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * Folder rows with DTO-01 aggregates (P5.T3). Exact people counters and bounded user previews are
	 * calculated in one streaming query. Company ids live in CRM, so document candidates are resolved
	 * in bounded batches until enough unique companies are found.
	 *
	 * The aggregates describe the whole folder content and are intentionally independent of the active
	 * search/filter: the filter narrows the document list (and gates folder visibility, see P5.T2), but
	 * the folder "card" must still show everyone in the folder, not just the current match.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function getSafeFolderAggregateRows(
		\Bitrix\Sign\Item\Document\SafeFolderCollection $folders,
	): array
	{
		if ($folders->count() === 0)
		{
			return [];
		}

		$folderIds = [];
		foreach ($folders as $folder)
		{
			$folderIds[] = $folder->getId();
		}

		$aggregates = $this->memberRepository->getSafeFolderAggregates(
			$folderIds,
			self::SAFE_FOLDER_PEOPLE_PREVIEW_LIMIT,
			self::SAFE_FOLDER_COMPANY_DOCUMENT_SCAN_LIMIT,
		);

		$companyDocumentOffsetByFolderId = [];
		$companiesByFolderId = [];
		while (true)
		{
			$documentIdsByFolderId = [];
			$roundDocumentIds = [];
			foreach ($aggregates as $folderId => $aggregate)
			{
				if (count($companiesByFolderId[$folderId] ?? []) >= self::SAFE_FOLDER_COMPANY_PREVIEW_LIMIT)
				{
					continue;
				}

				$offset = $companyDocumentOffsetByFolderId[$folderId] ?? 0;
				$documentIds = array_slice(
					$aggregate['companyDocumentIds'],
					$offset,
					self::SAFE_FOLDER_COMPANY_DOCUMENT_BATCH_LIMIT,
				);
				$companyDocumentOffsetByFolderId[$folderId] = $offset + count($documentIds);
				if ($documentIds === [])
				{
					continue;
				}

				$documentIdsByFolderId[$folderId] = $documentIds;
				foreach ($documentIds as $documentId)
				{
					$roundDocumentIds[$documentId] = true;
				}
			}

			if ($roundDocumentIds === [])
			{
				break;
			}

			$companyByDocumentId = $this->resolveCompaniesByDocuments(
				$this->documentRepository->listByIds(array_keys($roundDocumentIds)),
			);
			foreach ($documentIdsByFolderId as $folderId => $documentIds)
			{
				foreach ($documentIds as $documentId)
				{
					$company = $companyByDocumentId[$documentId] ?? null;
					if ($company !== null)
					{
						$companiesByFolderId[$folderId][$company['id']] = $company;
					}
					if (
						count($companiesByFolderId[$folderId] ?? [])
						>= self::SAFE_FOLDER_COMPANY_PREVIEW_LIMIT
					)
					{
						break;
					}
				}
			}
		}

		foreach ($aggregates as $folderId => &$aggregate)
		{
			$aggregate['companies'] = array_values($companiesByFolderId[$folderId] ?? []);
			unset($aggregate['companyDocumentIds']);
		}
		unset($aggregate);

		$relationIdMap = $this->getRelationIdMap($folderIds, FolderEntityType::FOLDER);

		$this->prefetchAggregateUsers($aggregates);

		$rows = [];
		foreach ($folders as $folder)
		{
			$folderId = $folder->getId();
			$aggregate = $aggregates[$folderId] ?? [];
			$rows[] = [
				'rowType' => 'folder',
				'id' => $folderId,
				'title' => $folder->title,
				'relationId' => $relationIdMap[$folderId] ?? null,
				'participants' => $this->formatAggregateUsers(array_slice(
					$aggregate['participantIds'] ?? [],
					0,
					self::SAFE_FOLDER_PEOPLE_PREVIEW_LIMIT,
				)),
				'participantCount' => $aggregate['participantCount'] ?? 0,
				'representatives' => $this->formatAggregateUsers(array_slice(
					$aggregate['representativeIds'] ?? [],
					0,
					self::SAFE_FOLDER_PEOPLE_PREVIEW_LIMIT,
				)),
				'representativeCount' => $aggregate['representativeCount'] ?? 0,
				'senders' => $this->formatAggregateUsers(array_slice(
					$aggregate['senderIds'] ?? [],
					0,
					self::SAFE_FOLDER_PEOPLE_PREVIEW_LIMIT,
				)),
				'senderCount' => $aggregate['senderCount'] ?? 0,
				'companies' => $aggregate['companies'] ?? [],
				'roles' => $this->formatAggregateRoles($aggregate['roleCodes'] ?? []),
				'canRename' => $this->safeAccessService->hasAccessToEdit($folder),
				'canDelete' => $this->safeAccessService->hasAccessToDelete($folder),
			];
		}

		return $rows;
	}

	/**
	 * @param list<int> $userIds
	 * @return list<array{id:int, name:string, photo:?string}>
	 */
	private function formatAggregateUsers(array $userIds): array
	{
		$result = [];
		foreach ($userIds as $userId)
		{
			$info = $this->getUsersInfo((int)$userId);
			$result[] = [
				'id' => $info['ID'],
				'name' => $info['FULL_NAME'],
				'photo' => $info['ICON'],
			];
		}

		return $result;
	}

	/**
	 * @param list<string> $roleCodes
	 * @return list<string>
	 */
	private function formatAggregateRoles(array $roleCodes): array
	{
		$captions = [];
		foreach ($roleCodes as $roleCode)
		{
			$captions[$this->getRoleInfoCaption($roleCode)] = true;
		}

		return array_keys($captions);
	}

	/**
	 * Batch-resolves the company {id,title} for the given safe documents (no N+1): one CRM item
	 * query for the mycompany ids and one lookup per unique company name; results are cached per
	 * CRM entity id for the request.
	 *
	 * @return array<int, array{id:int, title:string}|null> documentId => company
	 */
	private function resolveCompaniesByDocuments(Item\DocumentCollection $documents): array
	{
		$result = [];
		$entityIdByDocumentId = [];
		$pendingEntityIds = [];
		foreach ($documents as $document)
		{
			if (
				$document->entityType !== \Bitrix\Sign\Type\Document\EntityType::SMART_B2E
				|| !$document->entityId
			)
			{
				$result[$document->id] = null;
				continue;
			}

			$entityIdByDocumentId[$document->id] = $document->entityId;
			if (!array_key_exists($document->entityId, $this->safeCompanyByEntityId))
			{
				$pendingEntityIds[$document->entityId] = true;
			}
		}

		if (!empty($pendingEntityIds) && \Bitrix\Main\Loader::includeModule('crm'))
		{
			$this->loadCompaniesForEntityIds(array_keys($pendingEntityIds));
		}

		foreach ($entityIdByDocumentId as $documentId => $entityId)
		{
			$result[$documentId] = $this->safeCompanyByEntityId[$entityId] ?? null;
		}

		return $result;
	}

	/**
	 * @param list<int> $entityIds CRM SmartB2e document ids
	 */
	private function loadCompaniesForEntityIds(array $entityIds): void
	{
		$factory = \Bitrix\Crm\Service\Container::getInstance()->getFactory(CCrmOwnerType::SmartB2eDocument);
		if ($factory === null)
		{
			foreach ($entityIds as $entityId)
			{
				$this->safeCompanyByEntityId[$entityId] = null;
			}

			return;
		}

		$companyIdByEntityId = [];
		$items = $factory->getItems(['filter' => ['@ID' => $entityIds]]);
		foreach ($items as $item)
		{
			$companyId = $item->getMycompanyId();
			$companyIdByEntityId[$item->getId()] = $companyId ? (int)$companyId : null;
		}

		$uniqueCompanyIds = array_values(array_unique(array_filter($companyIdByEntityId)));
		$companyNameById = [];
		if (!empty($uniqueCompanyIds))
		{
			foreach (MyCompany::listItems(null, $uniqueCompanyIds) as $company)
			{
				$companyNameById[$company->id] = $company->name;
			}
		}

		foreach ($entityIds as $entityId)
		{
			$companyId = $companyIdByEntityId[$entityId] ?? null;
			if ($companyId === null || !isset($companyNameById[$companyId]))
			{
				$this->safeCompanyByEntityId[$entityId] = null;
				continue;
			}

			$this->safeCompanyByEntityId[$entityId] = [
				'id' => $companyId,
				'title' => (string)$companyNameById[$companyId],
			];
		}
	}

	private function getDocumentsDataForGrid(
		MemberCollection $memberCollection,
		Item\DocumentCollection $documentCollection,
		bool $isSafeExport = false,
	): array
	{
		$result = [];

		$documents = $documentCollection->getArrayByIds();

		$relationIdMap = [];
		if (!$isSafeExport)
		{
			$memberIds = [];
			foreach ($memberCollection as $member)
			{
				$memberIds[] = $member->id;
			}
			$relationIdMap = $this->getRelationIdMap($memberIds, FolderEntityType::MEMBER);
		}

		// Export path (P2.T3): warm the user cache in one chunked query so the per-row FIO lookups
		// (creator, representative, participant) hit the cache instead of issuing a query each.
		if ($isSafeExport)
		{
			$this->prefetchSafeExportUsers($memberCollection, $documents);
		}

		// DTO-01: the company is a column of its own, separate from the representative. Resolved in
		// batch for the whole page to avoid a per-document CRM lookup (N+1).
		$companyByDocumentId = isset($this->arResult['COLUMNS']['company'])
			? $this->resolveCompaniesByDocuments($documentCollection)
			: [];

		$currentCulture = Context::getCurrent()->getCulture();
		/** @var Item\Member $member */
		foreach ($memberCollection as $member)
		{
			$memberData = [];
			$document = $documents[$member->documentId];

			// DTO-01: document rows are tagged so the grid can tell them apart from folder rows.
			$memberData['rowType'] = 'document';
			$memberData['ID'] = $member->id;
			$memberData['relationId'] = $relationIdMap[$member->id] ?? null;

			if (isset($this->arResult['COLUMNS']['title']))
			{
				$withLink = !in_array($this->type, [self::PERSONAL_TYPE, self::CURRENT_TYPE], true);
				$title = $this->getDocumentTitle($document);
				$memberData['TITLE_INFO'] = $this->getTitleInfo($title, $document->entityId, $withLink);
			}

			if (isset($this->arResult['COLUMNS']['createdBy']))
			{
				try
				{
					$memberData['CREATED_BY'] = $this->getUsersInfo($document->createdById);
				}
				catch (ObjectPropertyException|ArgumentException|SystemException $e)
				{
				}
			}

			// The "download" column is a grid-only action button, dropped from the safe export (AC-002),
			// so the export path skips it entirely - never resolving the heavy per-row signed file URL.
			if (!$isSafeExport && isset($this->arResult['COLUMNS']['download']))
			{
				$resultFileInfo = $this->getResultFileInfo($member);
				$memberData['RESULT_FILE_INFO'] = $resultFileInfo;
			}

			if (isset($this->arResult['COLUMNS']['action']))
			{
				$isCurrentUserEqualsMember = CurrentUser::get()->getId() !== null
					&& Container::instance()
						->getMemberService()
						->isUserLinksWithMember($member, $document, CurrentUser::get()->getId())
				;
				if ($isCurrentUserEqualsMember && $this->isSigningLinkAvailable($member, $document))
				{
					$memberData['ACTION'] = [
						'TYPE' => 'link',
						'DATA' => [
							'role' => $member->role,
							'memberId' => $member->id,
						],
					];
				}
				elseif (
					$member->status === MemberStatus::DONE
					|| (
						$document->status === Type\DocumentStatus::STOPPED
						&& $member->status === Type\MemberStatus::READY
						&& $member->role === Role::ASSIGNEE
					)
				)
				{
					$resultFileInfo = $this->getResultFileInfo($member);
					if ($resultFileInfo !== null)
					{
						$memberData['ACTION'] = [
							'TYPE' => 'file',
							'DATA' => $resultFileInfo,
						];
					}
				}
			}

			if (isset($this->arResult['COLUMNS']['initiator']))
			{
				// Export needs only the representative FIO, so skip getSignWithInfo()'s per-row company
				// name resolve (the company has its own batch-resolved column).
				$memberData['INITIATOR'] = $isSafeExport
					? $this->getUsersInfo((int)$document->representativeId)
					: $this->getSignWithInfo($document);
			}

			// DTO-01: company {id,title}|null, rendered in the dedicated "Company" column.
			if (isset($this->arResult['COLUMNS']['company']))
			{
				$memberData['company'] = $companyByDocumentId[$member->documentId] ?? null;
			}

			if (isset($this->arResult['COLUMNS']['role']))
			{
				$memberData['ROLE'] = $this->getRoleInfoCaption($member->role);
			}

			if (isset($this->arResult['COLUMNS']['member']))
			{
				// Export passes the already-loaded document to getUserIdForMember() so a company-side
				// member does not re-fetch its document row per grid row.
				$memberData['MEMBER_INFO'] = $isSafeExport
					? $this->getUsersInfo($this->memberService->getUserIdForMember($member, $document) ?? 0)
					: $this->getMemberInfo($member);
			}

			if (isset($this->arResult['COLUMNS']['memberStatus']))
			{
				$memberData['MEMBER_STATUS'] = self::calculateStatus($member, $document);
			}

			if (isset($this->arResult['COLUMNS']['dateSign']) && $this->isMemberSignDateAllowedToShow($member))
			{
				$memberData['DATE_SIGN_INFO'] = $this->getDateSignWithInfo($member->dateSigned, $currentCulture);
			}

			if (isset($this->arResult['COLUMNS']['dateCreate']))
			{
				$memberData['DATE_CREATE_INFO'] = $this->getDateSignWithInfo($member->dateCreated, $currentCulture);
			}

			// Export-only "Folder" column (P2.T1): the record's folder title, or "No folder" when the
			// record is folderless. Titles come from the batch resolve, never a per-row query.
			if ($isSafeExport)
			{
				$folderTitle = (int)$member->folderId > 0
					? (string)($this->safeExportFolderTitles[(int)$member->folderId] ?? '')
					: '';
				$memberData['FOLDER_TITLE'] = $folderTitle !== ''
					? $folderTitle
					: Loc::getMessage('SIGN_DOCUMENT_LIST_SAFE_EXPORT_FOLDER_NONE');
			}

			$result[] = $memberData;
		}

		return $result;
	}

	/**
	 * Maps entity ids to their relation record id (b_sign_document_folder_relation.ID) for a single
	 * entity type, loaded in one query. An empty id list yields an empty map without hitting the DB.
	 *
	 * @param array<int|string> $entityIds
	 * @return array<int, int> entityId => relationId
	 */
	private function getRelationIdMap(array $entityIds, FolderEntityType $entityType): array
	{
		$entityIds = array_values(array_unique(array_filter(array_map('intval', $entityIds))));
		if (empty($entityIds))
		{
			return [];
		}

		$map = [];
		$rows = DocumentFolderRelationTable::getList([
			'select' => ['ID', 'ENTITY_ID'],
			'filter' => [
				'=ENTITY_TYPE' => $entityType->value,
				'@ENTITY_ID' => $entityIds,
			],
		]);
		foreach ($rows as $row)
		{
			$map[(int)$row['ENTITY_ID']] = (int)$row['ID'];
		}

		return $map;
	}

	private function getTitleInfo(string $title, int $id, bool $withLink): array
	{
		$result = [
			'TEXT' => $title,
		];

		if ($withLink)
		{
			$result ['DOCUMENT_LINK'] = Entity::getDetailPageUri(SmartB2e::getEntityTypeId(), $id);
		}

		return $result;
	}

	private function getUsersInfo(int $userId): array
	{
		if (isset($this->usersDataForGridById[$userId]))
		{
			return $this->usersDataForGridById[$userId];
		}

		return $this->usersDataForGridById[$userId] = $this->buildUserInfo(
			$userId,
			UserTable::getRowById($userId),
			$this->getUserNameTemplate(),
		);
	}

	/**
	 * Batch-prefetches user info for every user referenced by the given folder aggregates, so the
	 * per-row formatAggregateUsers() reads from the request cache instead of issuing one
	 * UserTable::getRowById per unique user (N+1).
	 *
	 * @param array<int, array<string, mixed>> $aggregates
	 */
	private function prefetchAggregateUsers(array $aggregates): void
	{
		$userIds = [];
		foreach ($aggregates as $aggregate)
		{
			foreach (['participantIds', 'representativeIds', 'senderIds'] as $key)
			{
				foreach ($aggregate[$key] ?? [] as $userId)
				{
					$userIds[] = (int)$userId;
				}
			}
		}

		$this->prefetchUsersByIds($userIds);
	}

	/**
	 * Warms the per-request user info cache for the safe export in one chunked query (P2.T3): the
	 * creator, the representative and the participant of every exported member, so the per-row FIO
	 * lookups become cache hits. The document is passed to getUserIdForMember() so a company-side
	 * member resolves its user without re-fetching the document row.
	 *
	 * @param array<int, Item\Document> $documents documentId => document
	 */
	private function prefetchSafeExportUsers(MemberCollection $members, array $documents): void
	{
		$userIds = [];
		foreach ($members as $member)
		{
			$document = $documents[$member->documentId] ?? null;
			if ($document === null)
			{
				continue;
			}

			$userIds[] = (int)$document->createdById;
			$userIds[] = (int)$document->representativeId;

			$memberUserId = $this->memberService->getUserIdForMember($member, $document);
			if ($memberUserId !== null)
			{
				$userIds[] = (int)$memberUserId;
			}
		}

		// The excel export uses only FULL_NAME, never the avatar, so warm the cache without resizing
		// photos: on the 10000-row ceiling this avoids thousands of needless CFile::ResizeImageGet calls.
		$this->prefetchUsersByIds($userIds, withPhoto: false);
	}

	/**
	 * Loads user info for the given ids into the per-request cache in chunked getList queries, skipping
	 * ids already cached. CFile has no batch resize, so photos are still resized per cache miss; pass
	 * $withPhoto = false to skip resizing entirely when only names are needed (export path).
	 *
	 * @param list<int> $userIds
	 */
	private function prefetchUsersByIds(array $userIds, bool $withPhoto = true): void
	{
		$pending = [];
		foreach ($userIds as $userId)
		{
			$userId = (int)$userId;
			if ($userId > 0 && !isset($this->usersDataForGridById[$userId]))
			{
				$pending[$userId] = true;
			}
		}

		if (empty($pending))
		{
			return;
		}

		$userNameTemplate = $this->getUserNameTemplate();
		$select = $withPhoto
			? ['ID', 'LOGIN', 'NAME', 'LAST_NAME', 'SECOND_NAME', 'PERSONAL_PHOTO']
			: ['ID', 'LOGIN', 'NAME', 'LAST_NAME', 'SECOND_NAME'];
		foreach (array_chunk(array_keys($pending), 300) as $chunk)
		{
			$rows = UserTable::getList([
				'select' => $select,
				'filter' => ['@ID' => $chunk],
			]);
			while ($row = $rows->fetch())
			{
				$fetchedId = (int)$row['ID'];
				$this->usersDataForGridById[$fetchedId] = $this->buildUserInfo(
					$fetchedId,
					$row,
					$userNameTemplate,
					$withPhoto,
				);
			}
		}
	}

	private function getUserNameTemplate(): string
	{
		return empty($this->arParams['USER_NAME_TEMPLATE'])
			? CSite::GetNameFormat(false)
			: str_replace(["#NOBR#", "#/NOBR#"], ["", ""], $this->arParams["USER_NAME_TEMPLATE"])
		;
	}

	/**
	 * @param array<string, mixed>|null $userRow
	 * @return array{ID:int, FULL_NAME:string, ICON:?string, LINK:string}
	 */
	private function buildUserInfo(int $userId, ?array $userRow, string $userNameTemplate, bool $withPhoto = true): array
	{
		$userFullName = $userRow === null
			? ''
			: CUser::FormatName(
				$userNameTemplate,
				[
					'LOGIN' => $userRow['LOGIN'],
					'NAME' => $userRow['NAME'],
					'LAST_NAME' => $userRow['LAST_NAME'],
					'SECOND_NAME' => $userRow['SECOND_NAME'],
				],
				true,
				false,
			);

		$userIconFileTmp = ($userRow === null || !$withPhoto)
			? null
			: CFile::ResizeImageGet(
				$userRow['PERSONAL_PHOTO'],
				self::DOCUMENT_CREATOR_ICON_SIZE,
				BX_RESIZE_IMAGE_EXACT,
				false,
				false,
				true,
			);
		$userIcon = ($userIconFileTmp && isset($userIconFileTmp['src'])) ? $userIconFileTmp['src'] : null;

		$userPageUrlLink = CComponentEngine::makePathFromTemplate(
			self::PATH_TO_USER_PROFILE_TEMPLATE,
			[self::URL_TO_USER_PROFILE_TEMPLATE_USER_KEY => $userId],
		);

		return [
			'ID' => $userId,
			'FULL_NAME' => $userFullName,
			'ICON' => $userIcon,
			'LINK' => $userPageUrlLink,
		];
	}

	private function getResultFileInfo(Item\Member $member): ?array
	{
		$data = $this->getDownloadResultFileUrl(
			\Bitrix\Sign\Type\EntityType::MEMBER,
			$member->id,
			EntityFileCode::SIGNED,
		);

		$dataPrinted = $this->getDownloadResultFileUrl(
			\Bitrix\Sign\Type\EntityType::MEMBER,
			$member->id,
			EntityFileCode::PRINT_VERSION,
		);

		if ($data === null)
		{
			return null;
		}

		return [
			'EXTENSION' => $data['ext'],
			'DOWNLOAD_URL' => $data['url'],
			'DOWNLOAD_URL_PRINTED' => $dataPrinted['url'] ?? null,
		];
	}

	private function getDownloadResultFileUrl(int $entityTypeId, int $entityId, int $fileCode): ?array
	{
		$operation = new Bitrix\Sign\Operation\GetSignedB2eFileUrl($entityTypeId, $entityId, $fileCode);
		$result = $operation->launch();
		if (!$result->isSuccess() || !$operation->ready)
		{
			return null;
		}

		$data = $result->getData();

		if (!isset($data['url']))
		{
			return null;
		}

		return $data;
	}

	private function getDateSignWithInfo(?\Bitrix\Main\Type\DateTime $dateSign, Context\Culture $culture): ?array
	{
		if ($dateSign === null)
		{
			return null;
		}

		$signDateTime = $dateSign;

		$signDateTime = clone $signDateTime;

		$signDateTime->setDefaultTimeZone();
		$signDateTimeZone = $signDateTime->getTimezone();

		$dateFormat = $this->getDefaultDateFormat($culture);

		return $this->getTextWithDetailInfoFormat(
			FormatDate($dateFormat, $signDateTime->getTimestamp()),
			$this->getDateTimeWithTimezoneOffsetRepresentation($signDateTime, $signDateTimeZone)
		);
	}

	private function getDefaultDateFormat(Context\Culture $culture): string
	{
		return $culture->getMediumDateFormat();
	}

	private function getTextWithDetailInfoFormat(string $text, string $detailInfo): array
	{
		return [
			'TEXT' => $text,
			'DETAIL' => $detailInfo,
		];
	}

	private function getDateTimeWithTimezoneOffsetRepresentation(
		\Bitrix\Main\Type\DateTime $dateTime,
		DateTimeZone $timezone
	): string
	{
		$dateTime = clone $dateTime;
		$dateTime->setTimeZone($timezone);

		return $dateTime . ' (UTC' . $dateTime->format('P') . ')';
	}

	private function getFilterId(): string
	{
		return match ($this->type)
		{
			self::PERSONAL_TYPE => self::PERSONAL_DOCUMENT_FILTER_ID,
			self::DOCUMENT_TYPE => self::DOCUMENT_DOCUMENT_FILTER_ID,
			self::SAFE_TYPE => self::SAFE_DOCUMENT_FILTER_ID,
			self::CURRENT_TYPE => self::CURRENT_DOCUMENT_FILTER_ID,
			default => self::DEFAULT_FILTER_ID,
		};
	}

	private function prepareFilters(): void
	{
		$this->arResult['FILTER_ID'] = $this->getFilterId();
		$this->arResult['FILTER'] = $this->getFilterItems();
		$this->arResult['FILTER_PRESETS'] = $this->getFilterPresets();
	}

	private function getFilterItems(): array
	{
		return match ($this->type)
		{
			self::DOCUMENT_TYPE => $this->getFilterItemsForDocument(),
			self::PERSONAL_TYPE => $this->getFilterItemsForPersonal(),
			self::SAFE_TYPE => $this->getFilterItemsForSafe(),
			self::CURRENT_TYPE => $this->getFilterItemsForCurrent(),
		};
	}

	private function getAvailableFilterItems(): array
	{
		$items = [
			'id' => [
				'id' => 'ID',
				'name' => 'ID',
				'default' => false,
			],
			'dateSign' => [
				'id' => 'DATE_SIGN',
				'name' => Loc::getMessage('SIGN_DOCUMENT_LIST_TITLE_FILTER_DATE_SIGN_NAME'),
				'type' => 'date',
				'default' => false,
			],
			'dateCreate' => [
				'id' => 'DATE_CREATE',
				'name' => Loc::getMessage('SIGN_DOCUMENT_LIST_TITLE_FILTER_DATE_CREATE_NAME'),
				'type' => 'date',
				'default' => false,
			],
			'employee' => [
				'id' => 'ENTITY_ID',
				'name' => Loc::getMessage('SIGN_DOCUMENT_LIST_TITLE_FILTER_LABEL_EMPLOYEE'),
				'type' => 'entity_selector',
				'partial' => true,
				'default' => false,
				'params' => [
					'multiple' => 'Y',
					'dialogOptions' => [
						'height' => 240,
						'context' => 'filter',
						'entities' => [
							[
								'id' => 'user',
								'options' => [
									'inviteEmployeeLink' => false,
								],
							],
							[
								'id' => 'sign-fired-user',
							],
						],
					],
				],
			],
			'role' => [
				'id' => 'ROLE',
				'name' => Loc::getMessage('SIGN_DOCUMENT_LIST_TITLE_FILTER_LABEL_ROLE'),
				'type' => 'list',
				'default' => false,
				'params' => [
					'multiple' => 'Y',
				],
				'items' => array_combine(
					Role::getAll(),
					array_map(fn($role): string => $this->getRoleInfoCaption($role), Role::getAll())
				),
			],
			'memberStatus' => [
				'id' => 'MEMBER_STATUS',
				'name' => Loc::getMessage('SIGN_DOCUMENT_LIST_TITLE_FILTER_LABEL_MEMBER_STATUS'),
				'type' => 'list',
				'default' => false,
				'params' => [
					'multiple' => 'Y',
				],
				'items' => $this->getMemberAllStatusesCaption(),
			],
			'representative' => [
				'id' => 'REPRESENTATIVE',
				'name' => Loc::getMessage('SIGN_DOCUMENT_LIST_TITLE_FILTER_LABEL_REPRESENTATIVE'),
				'type' => 'entity_selector',
				'partial' => true,
				'default' => false,
				'params' => [
					'multiple' => 'Y',
					'dialogOptions' => [
						'height' => 240,
						'context' => 'filter',
						'entities' => [
							[
								'id' => 'user',
								'options' => [
									'inviteEmployeeLink' => false,
								],
							],
						],
					],
				],
			],
			'company' => [
				'id' => 'COMPANY',
				'name' => Loc::getMessage('SIGN_DOCUMENT_LIST_TITLE_FILTER_LABEL_COMPANY'),
				'type' => 'entity_selector',
				'partial' => true,
				'default' => false,
				'params' => [
					'multiple' => 'Y',
					'dialogOptions' => [
						'height' => 240,
						'dropdownMode' => false,
						'entities' => [
							[
								'id' => 'sign-mycompany',
								'dynamicLoad' => true,
								'dynamicSearch' => true,
								'options' => [
									'enableMyCompanyOnly' => true,
								],
							],
						],
					],
				],
			],
			'document' => [
				'id' => 'DOCUMENT_NAME',
				'name' => Loc::getMessage('SIGN_DOCUMENT_LIST_TITLE_FILTER_LABEL_DOCUMENT_NAME'),
				'type' => 'string',
			],
			'initiator' => [
				'id' => 'CREATED_BY_ID',
				'name' => Loc::getMessage('SIGN_DOCUMENT_LIST_TITLE_FILTER_LABEL_INITIATOR'),
				'type' => 'entity_selector',
				'partial' => true,
				'default' => false,
				'params' => [
					'multiple' => 'Y',
					'dialogOptions' => [
						'height' => 240,
						'context' => 'filter',
						'entities' => [
							[
								'id' => 'user',
								'options' => [
									'inviteEmployeeLink' => false,
								],
							],
						],
					],
				],
			],
			'action' => [
				'id' => 'ACTION',
				'name' => Loc::getMessage('SIGN_DOCUMENT_LIST_TITLE_FILTER_LABEL_ACTION'),
				'type' => 'string',
				'default' => false,
			],
		];

		return $items;
	}

	private function getFilterItemsForDocument(): array
	{
		$items = [
			'dateSign' => ['default' => true,],
			'role' => ['default' => true,],
			'memberStatus' => ['default' => true,],
			'employee' => ['default' => true,],
		];

		return $this->filterFilteredItems($items);
	}

	private function getFilterItemsForPersonal(): array
	{
		$items = [
			'dateSign' => ['default' => true],
			'representative' => ['default' => true],
			'company' => ['default' => true],
			'document' => ['default' => true],
			'initiator' => [],
		];

		return $this->filterFilteredItems($items);
	}

	private function getFilterItemsForSafe(): array
	{
		$items = [
			'dateSign' => ['default' => true],
			'dateCreate' => [],
			'employee' => ['default' => true],
			'representative' => ['default' => true],
			'document' => ['default' => true],
			'company' => ['default' => true],
			'initiator' => [],
		];

		return $this->filterFilteredItems($items);
	}

	private function getFilterPresets(): array
	{
		return [];
	}

	private function getUseDefaultStubParamValue(): bool
	{
		$filterOptions = $this->getFilterOptions();
		if (trim($filterOptions->getSearchString()))
		{
			return true;
		}

		if (count($this->getRequestFilters($filterOptions)) > 0)
		{
			return true;
		}

		return false;
	}

	private function getFilterOptions(): Filter\Options
	{
		return new Filter\Options($this->arResult['FILTER_ID']);
	}

	private function getRequestFilters(Filter\Options $filterOptions): array
	{
		return $filterOptions->getFilter($this->arResult['FILTER']);
	}

	public function prepareStub(): void
	{
		$stubTitle = match ($this->type) {
			self::PERSONAL_TYPE,
			self::SAFE_TYPE,
			self::DOCUMENT_TYPE => Loc::getMessage('SIGN_DOCUMENT_LIST_TITLE_STUB_NO_DOCUMENT'),
			self::CURRENT_TYPE => Loc::getMessage('SIGN_DOCUMENT_LIST_TITLE_STUB_NO_DOCUMENT_FOR_SIGN')
		};
		$this->setResult('STUB', ['title' => $stubTitle]);

		$this->setResult(
			'USE_DEFAULT_STUB',
			$this->getParam('USE_DEFAULT_STUB') ?? $this->getUseDefaultStubParamValue()
		);
	}

	private function prepareNavigationParams(): void
	{
		$this->arResult['PAGE_SIZE'] =
			isset($this->arParams['PAGE_SIZE']) && (int)$this->arParams['PAGE_SIZE'] > 0
				? (int)$this->arParams['PAGE_SIZE']
				: self::DEFAULT_PAGE_SIZE
		;
		$this->arResult['NAVIGATION_KEY'] = $this->arParams['NAVIGATION_KEY'] ?? self::DEFAULT_NAV_KEY;
	}

	private function getNavigation()
	{
		if (!isset($this->arResult['NAVIGATION_OBJECT']))
		{
			return $this->prepareNavigation();
		}

		return $this->arResult['NAVIGATION_OBJECT'];
	}

	private function prepareNavigation(): PageNavigation
	{
		$pageNavigation = new PageNavigation($this->arResult['NAVIGATION_KEY']);
		$pageNavigation
			->setPageSize($this->arResult['PAGE_SIZE'])
			->allowAllRecords(false)
			->initFromUri()
		;

		// A bitrix:main.ui.grid column-sort reload reuses the current URL, which
		// still carries the page-nav parameter, so initFromUri() would keep the
		// safe grid on the current page after re-sorting. Restart paging from the
		// first page on a sort action, matching the standard Bitrix grid behaviour.
		// Scoped to the safe branch so personal/document/current paging is untouched.
		if ($this->type === self::SAFE_TYPE && $this->isSafeGridSortRequest())
		{
			$pageNavigation->setCurrentPage(1);
		}

		$this->arResult['NAVIGATION_OBJECT'] = $pageNavigation;

		return $pageNavigation;
	}

	/**
	 * Detects a bitrix:main.ui.grid column-sort reload for the safe grid.
	 *
	 * On a sortable header click the grid reloads with grid_action=sort in the
	 * query string (see the bitrix:main.ui.grid frontend), reusing the current
	 * URL. The grid_id guard keeps the reset bound to this grid, so an unrelated
	 * grid's sort on the same page never restarts the safe grid paging.
	 */
	private function isSafeGridSortRequest(): bool
	{
		$request = Context::getCurrent()->getRequest();

		return $request->getQuery('grid_action') === 'sort'
			&& $request->getQuery('grid_id') === ($this->arResult['GRID_ID'] ?? null);
	}

	private function getDocumentsByMembers(
		MemberCollection $members,
		bool $isSafeExport = false,
	): Item\DocumentCollection
	{
		$ids = [];
		foreach ($members as $member)
		{
			$ids[] = $member->documentId;
		}

		return $isSafeExport
			? $this->documentRepository->listForSafeExportByIds($ids)
			: $this->documentRepository->listByIds(array_unique($ids))
		;
	}

	private function getSignWithInfo(Item\Document $document): array
	{
		$userInfo = $this->getUsersInfo($document->representativeId);
		$userInfo['COMPANY_NAME'] = $this->getCompanyName($document);

		return $userInfo;
	}

	private function getCounterItems(Item\Document $document, ?ConditionTree $filter): array
	{
		$counters = $this->memberRepository->getMembersCountersByDocument($document, null);

		return [
			[
				'id' => self::PREFIX_FOR_SHORT_FILTER_MEMBER_STATUS . MemberStatus::DONE,
				'title' => Loc::getMessage('SIGN_DOCUMENT_LIST_GRID_COUNTER_SIGNED'),
				'value' => $counters['SUCCESS_MEMBERS_COUNTER'],
				'color' =>'THEME',
				'isRestricted' => false,
			],
			[
				'id' => self::PREFIX_FOR_SHORT_FILTER_MEMBER_STATUS . MemberStatus::READY,
				'title' => Loc::getMessage('SIGN_DOCUMENT_LIST_GRID_COUNTER_READY'),
				'value' => $counters['READY_MEMBERS_COUNTER'],
				'color' => $counters['READY_MEMBERS_COUNTER'] === 0 ? 'THEME' : 'WARNING',
				'isRestricted' => false,
			],
			[
				'id' => self::PREFIX_FOR_SHORT_FILTER_MEMBER_STATUS . MemberStatus::REFUSED,
				'title' => Loc::getMessage('SIGN_DOCUMENT_LIST_GRID_COUNTER_REFUSED'),
				'value' => $counters['REFUSED_MEMBERS_COUNTER'],
				'color' => 'THEME',
				'isRestricted' => false,
			],
		];
	}

	private function getMemberInfo(Item\Member $member): array
	{
		return $this->getUsersInfo($this->memberService->getUserIdForMember($member) ?? 0);
	}

	private function getCompanyName(Item\Document $document): ?string
	{
		if ($document->entityType === \Bitrix\Sign\Type\Document\EntityType::SMART_B2E)
		{
			if (!isset($this->companyData[$document->entityId]))
			{
				$factory = \Bitrix\Crm\Service\Container::getInstance()->getFactory(CCrmOwnerType::SmartB2eDocument);
				$companyId = $factory->getItem($document->entityId)?->getMycompanyId();
				if ($companyId === null)
				{
					$this->companyData[$document->entityId] = null;

					return null;
				}

				$companyName = MyCompany::getById($companyId)?->name;

				$this->companyData[$document->entityId] = $companyName;
			}

			return $this->companyData[$document->entityId];
		}

		return null;
	}


	/**
	 * @param array $data
	 *
	 * @return array<int>
	 */
	private function prepareCollectionIds(array $data): array
	{
		return array_map(
			static fn($item): int => (int)$item,
			array_filter($data, static fn ($item) => (int)$item > 0)
		);
	}

	private function prepareEvents(): void
	{
		if (Bitrix\Main\Loader::includeModule('pull'))
		{
			CPullWatch::Add(
				CurrentUser::get()->getId(),
				Handler::FILTER_COUNTER_TAG);
		}
	}

	private function getRoleInfoCaption(string $role): string
	{
		return match ($role) {
			Role::ASSIGNEE => Loc::getMessage('SIGN_DOCUMENT_LIST_ROLE_CAPTION_ASSIGNEE'),
			Role::EDITOR => Loc::getMessage('SIGN_DOCUMENT_LIST_ROLE_CAPTION_EDITOR_MSG_1'),
			Role::REVIEWER => Loc::getMessage('SIGN_DOCUMENT_LIST_ROLE_CAPTION_REVIEWER'),
			Role::SIGNER => Loc::getMessage('SIGN_DOCUMENT_LIST_ROLE_CAPTION_SIGNER'),
			default => Loc::getMessage('SIGN_DOCUMENT_LIST_ROLE_CAPTION_UNDEFINED')
		};
	}

	private function getMemberAllStatusesCaption(): array
	{
		return [
			MemberStatus::DONE => Loc::getMessage('SIGN_DOCUMENT_LIST_GRID_COUNTER_SIGNED'),
			MemberStatus::READY => Loc::getMessage('SIGN_DOCUMENT_LIST_GRID_COUNTER_READY'),
			MemberStatus::REFUSED => Loc::getMessage('SIGN_DOCUMENT_LIST_GRID_COUNTER_REFUSED'),
			MemberStatus::WAIT => Loc::getMessage('SIGN_DOCUMENT_LIST_GRID_COUNTER_WAIT'),
			MemberStatus::STOPPED => Loc::getMessage('SIGN_DOCUMENT_LIST_GRID_COUNTER_STOPPED'),
		];
	}

	private function prepareBannerParams(): void
	{
		if (!in_array($this->type, [self::PERSONAL_TYPE, self::SAFE_TYPE], true))
		{
			$this->arResult['IS_SHOW_B2E_GRID_BANNER'] = false;

			return;
		}

		$this->arResult['IS_SHOW_B2E_GRID_BANNER'] = true;
		if ($this->type === self::SAFE_TYPE )
		{
			if (CUserOptions::GetOption('sign', self::BANNER_OPTION_CLOSE_SAFE, 'Y', CurrentUser::get()->getId()) !== 'Y')
			{
				$this->arResult['IS_SHOW_B2E_GRID_BANNER'] = false;
				return;
			}
		}
		elseif ($this->type === self::PERSONAL_TYPE)
		{
			if (CUserOptions::GetOption('sign', self::BANNER_OPTION_CLOSE_PERSONAL, 'Y', CurrentUser::get()->getId()) !== 'Y')
			{
				$this->arResult['IS_SHOW_B2E_GRID_BANNER'] = false;
				return;
			}
		}
		$this->arResult['BANNER_TEXT'] = Loc::getMessage('SIGN_DOCUMENT_GRID_BANNER_TEXT_MSGVER_1');
	}

	public function setBannerOptionCloseAction($type): void
	{
		if (!in_array($type, [self::PERSONAL_TYPE, self::SAFE_TYPE], true))
		{
			return;
		}

		$option = match ($type) {
			self::PERSONAL_TYPE => self::BANNER_OPTION_CLOSE_PERSONAL,
			self::SAFE_TYPE => self::BANNER_OPTION_CLOSE_SAFE,
		};

		CUserOptions::SetOption('sign', $option, 'N', false, CurrentUser::get()->getId());
	}

	public function configureActions(): void
	{
		return;
	}

	private function prepareFilterForCompanyFilterField(ConditionTree $filter, array $requestFilter): void
	{
		$prefix = \Bitrix\Sign\Repository\MemberRepository::SIGN_DOCUMENT_LIST_QUERY_REF_FIELD_NAME_COMPANY;
		$filter
			->where($prefix . '.ENTITY_TYPE', \Bitrix\Sign\Type\Member\EntityType::COMPANY)
			->where($prefix . '.ROLE', $this->memberRepository->convertRoleToInt(Role::ASSIGNEE))
			->whereIn( $prefix . '.ENTITY_ID', (array)$requestFilter['COMPANY'])
		;
	}

	private function getFilterItemsForCurrent(): array
	{
		$items = [];
		return $this->filterFilteredItems($items);
	}

	private function getGridColumnsListForCurrent(): array
	{
		return $this->filterGridColumnsWithDefault([
			'id' => [],
			'title' => [
				'default' => true,
				'gridSort' => 100,
			],
			'role' => [
				'default' => true,
				'gridSort' => 300,
			],
			'createdBy' => [
				'default' => true,
				'gridSort' => 200,
			],
			'action' => [
				'default' => true,
				'gridSort' => 800,
			],
		]);
	}

	private function getCurrentMemberCollection(): MemberCollection
	{
		return $this->memberRepository->listB2eMembersWithReadyStatus(
			$this->entityId,
			$this->getLimitForQuery(),
			$this->getOffsetForQuery()
		);
	}

	private static function calculateStatus(Item\Member $member, Item\Document $document): array
	{
		$stageInfo = Ui\Member\Stage::createInstance($member, $document)->getInfo();

		return [
			'TEXT' => $stageInfo['text'],
			'COLOR' => $stageInfo['color'],
			'IDENTIFIER' => 'sign_document_grid_label_id_' . $member->id,
		];
	}

	private function isSigningLinkAvailable(Item\Member $member, Item\Document $document): bool
	{
		if (
			$document->providerCode === Type\ProviderCode::GOS_KEY
			&& $member->role === Role::SIGNER
			&& $member->status === MemberStatus::READY
		)
		{
			return true;
		}

		return MemberStatus::isReadyForSigning($member->status)
			&& !in_array($document->status, Type\DocumentStatus::getFinalStatuses(), true)
			;
	}

	private function isMemberSignDateAllowedToShow(Item\Member $member): bool
	{
		if ($member->role === Role::ASSIGNEE && $member->status !== MemberStatus::DONE)
		{
			return false;
		}

		return true;
	}

	private function getDocumentTitle(Item\Document $document): string
	{
		return $this->type === self::PERSONAL_TYPE
			? $this->documentService->getComposedTitleByDocument($document)
			: $this->documentService->getTitleWithAutoNumber($document);
	}

	private function getExcelFileName(string $documentName): string
	{
		$extension = '.xls';
		$fileName = File::sanitizeFilename($documentName . $extension);
		return $fileName !== null ? $fileName : self::EXCEL_DEFAULT_FILE_NAME . $extension;
	}

	private function getVisibleGridColumns(): array
	{
		$visibleColumnIds = $this->getGridOptions()->GetVisibleColumns();
		if (!is_array($visibleColumnIds) || $visibleColumnIds === [])
		{
			return $this->getDefaultVisibleGridColumns();
		}

		$availableColumnsById = [];
		foreach ($this->arResult['COLUMNS'] as $column)
		{
			$columnId = $column['id'] ?? null;
			if (is_string($columnId) && $columnId !== '')
			{
				$availableColumnsById[$columnId] = $column;
			}
		}

		$visibleColumns = [];
		foreach ($visibleColumnIds as $columnId)
		{
			if (is_string($columnId) && isset($availableColumnsById[$columnId]))
			{
				$visibleColumns[] = $availableColumnsById[$columnId];
			}
		}

		return $visibleColumns !== [] ? $visibleColumns : $this->getDefaultVisibleGridColumns();
	}

	private function getDefaultVisibleGridColumns(): array
	{
		return array_values(array_filter(
			$this->arResult['COLUMNS'],
			static fn(array $column): bool => (bool)($column['default'] ?? false),
		));
	}

	private function getVisibleColumnsForExcel(): array
	{
		$visibleColumns = $this->getVisibleGridColumns();
		$isSafeExport = $this->type === self::SAFE_TYPE && $this->isExcelExportMode();
		$columns = [];
		foreach ($visibleColumns as $visibleColumn)
		{
			// ACTION is always a grid-only button. In the safe export the "download" column
			// (SIGN_DOCUMENT_LIST_COLUMN_NAME_DOWNLOAD_ACTION_MSGVER_1 = "Action") is the same button,
			// and every safe record is signed, so it carries no useful text - drop it too (AC-002).
			if ($visibleColumn['id'] === 'ACTION' || ($isSafeExport && $visibleColumn['id'] === 'DOWNLOAD_DOCUMENT'))
			{
				continue;
			}

			$columns[] = $visibleColumn;
		}

		// Export-only "Folder" column (P2.T1): appended to the safe .xls only, so it never reaches the
		// on-screen grid. The per-row value is filled by getDocumentsDataForGrid() in export mode.
		if ($isSafeExport)
		{
			$columns[] = [
				'id' => 'FOLDER',
				'name' => Loc::getMessage('SIGN_DOCUMENT_LIST_COLUMN_NAME_FOLDER'),
			];
		}

		return $columns;
	}

}
