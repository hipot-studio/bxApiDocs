<?php

use Bitrix\Disk\Configuration;
use Bitrix\Disk\Internal\Dto\Audit\AuditFilter;
use Bitrix\Disk\Internal\Dto\Audit\AuditListItem;
use Bitrix\Disk\Internal\Dto\Audit\AuditPage;
use Bitrix\Disk\Internal\Dto\Audit\AuditState;
use Bitrix\Disk\Internal\Entity\Audit\AuditInitiator;
use Bitrix\Disk\Internal\Entity\Audit\AuditTarget;
use Bitrix\Disk\Internal\Enum\Audit\AuditEventCode;
use Bitrix\Disk\Internal\Service\Audit\AuditContextNameResolver;
use Bitrix\Disk\Internal\Service\Audit\AuditPageInstanceTokenCodec;
use Bitrix\Disk\Internal\Service\Audit\AuditQueryService;
use Bitrix\Disk\Internal\Service\Audit\AuditSettingsEventFactory;
use Bitrix\Disk\Internals\BaseComponent;
use Bitrix\Disk\Internals\Engine\Contract\SidePanelWrappable;
use Bitrix\Disk\Ui\Avatar;
use Bitrix\Main;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

Main\Loader::requireModule('disk');
Main\Localization\Loc::loadMessages(__FILE__);

class CDiskAuditLogComponent extends BaseComponent implements SidePanelWrappable
{
	public const GRID_ID = 'diskAuditLogGrid';
	public const FILTER_ID = 'diskAuditLogFilter';

	private const NAVIGATION_ID = 'diskAuditLogPage';
	private const DEFAULT_PAGE_SIZE = 20;
	private const MAX_PAGE_SIZE = 100;
	private const FILTER_REQUEST_FIELDS = [
		'PERIOD_from',
		'PERIOD_to',
		'INITIATOR_USER',
		'INITIATOR_TYPE',
		'INITIATOR_ID',
		'EVENT_CODE',
		'TARGET_TYPE',
		'STORAGE_ID',
		'CHANNEL',
		'FIND',
	];
	/** Icons of the ui.icon-set.disk set: the value is an icon name without the `--` prefix. */
	private const FILE_ICON_CLASSES = [
		'doc' => 'doc',
		'docx' => 'docx',
		'xls' => 'xls',
		'xlsx' => 'xlsx',
		'ppt' => 'ppt',
		'pptx' => 'pptx',
		'pdf' => 'pdf',
		'txt' => 'txt',
		'zip' => 'zip',
		'rar' => 'rar',
		'psd' => 'psd',
		'php' => 'php',
		'board' => 'board',
		'odt' => 'odt',
		'ods' => 'ods',
		'odp' => 'odp',
		'odf' => 'odf',
		'jpg' => 'image',
		'jpeg' => 'image',
		'png' => 'image',
		'gif' => 'image',
		'webp' => 'image',
		'svg' => 'image',
		'mp4' => 'video',
		'avi' => 'video',
		'mov' => 'video',
		'mkv' => 'video',
		'mp3' => 'audio',
		'wav' => 'audio',
		'ogg' => 'audio',
	];

	private const AVATAR_SIZE = 40;

	/** Keep in step with the --tint-N rules in the template stylesheet and in event-card.css. */
	private const TINT_COUNT = 4;

	/** Targets named after the file or folder the action was applied to. */
	private const OBJECT_NAMED_TARGETS = [
		AuditTarget::TYPE_RIGHTS,
		AuditTarget::TYPE_PUBLIC_LINK,
		AuditTarget::TYPE_UNIFIED_LINK,
	];

	/** Icons of the AIR set ui.icon-set.outline for targets that are not a file or a folder. */
	private const NON_OBJECT_TARGET_ICONS = [
		AuditTarget::TYPE_RIGHTS => 'lock-m',
		AuditTarget::TYPE_PUBLIC_LINK => 'o-link',
		AuditTarget::TYPE_UNIFIED_LINK => 'o-link',
		AuditTarget::TYPE_TRASHCAN => 'o-trashcan',
		AuditTarget::TYPE_AUDIT_SETTINGS => 'o-settings',
		AuditTarget::TYPE_AUDIT_LOG => 'o-login-history',
	];

	/**
	 * Targets that carry no object name of their own: the rest, including rights and both
	 * kinds of link, keep the name of the file or folder the action was applied to.
	 */
	private const NAMELESS_TARGETS = [
		AuditTarget::TYPE_TRASHCAN,
		AuditTarget::TYPE_AUDIT_SETTINGS,
		AuditTarget::TYPE_AUDIT_LOG,
	];

	/** Glyphs for initiators that are not a person and so have no avatar of their own. */
	private const INITIATOR_ICONS = [
		AuditInitiator::TYPE_SYSTEM => 'o-settings',
		AuditInitiator::TYPE_APPLICATION => 'o-robot',
	];

	private const ENDPOINTS = [
		'list' => 'disk.Audit.list',
		'get' => 'disk.Audit.get',
		'getFilterOptions' => 'disk.Audit.getFilterOptions',
		'recordPageOpened' => 'disk.Audit.recordPageOpened',
		'getSettings' => 'disk.AuditSettings.get',
		'updateSettings' => 'disk.AuditSettings.update',
	];

	private function isColumnShown(Main\Grid\Options $gridOptions, string $columnId): bool
	{
		$defaultColumns = array_column(
			array_filter($this->getGridHeaders(), static fn(array $header): bool => ($header['default'] ?? true) !== false),
			'id',
		);

		return in_array($columnId, $gridOptions->getUsedColumns($defaultColumns), true);
	}

	protected function createContextNameResolver(): AuditContextNameResolver
	{
		return new AuditContextNameResolver();
	}

	protected function processBeforeAction($actionName): bool
	{
		if (!Configuration::isAuditFeatureEnabled() || !$this->getUser()->IsAdmin())
		{
			$this->showAccessDenied();

			return false;
		}

		return parent::processBeforeAction($actionName);
	}

	protected function processActionDefault(): void
	{
		$this->arParams['FILTER_OPTIONS'] = $this->loadFilterOptions();
		$filterDefinition = $this->getFilterDefinition();
		$filterPresets = $this->getFilterPresets();
		$rawFilter = $this->createFilterOptions($filterPresets)->getFilter($filterDefinition);
		$rawFilter = $this->mergeRequestFilterData(
			$rawFilter,
			$this->request->getPost('auditFilter'),
		);
		$gridOptions = new Main\Grid\Options(self::GRID_ID);
		$navigation = $this->createNavigation($gridOptions);
		$page = $this->loadPage($rawFilter, $navigation);

		$navigation->setRecordCount($page->total);

		$this->arResult = [
			'GRID_ID' => self::GRID_ID,
			'FILTER_ID' => self::FILTER_ID,
			'PAGE_URL' => SITE_DIR . 'disk/audit/',
			'PAGE_INSTANCE_ID' => $this->issuePageInstanceId(),
			'ENDPOINTS' => self::ENDPOINTS,
			'HEADERS' => $this->getGridHeaders(),
			'ROWS' => $this->formatRows($page->items, $this->isColumnShown($gridOptions, 'CONTEXT')),
			'FILTER' => $filterDefinition,
			'FILTER_PRESETS' => $filterPresets,
			'NAV_OBJECT' => $navigation,
			'CURRENT_PAGE' => $page->page,
			'PAGE_SIZE' => $page->pageSize,
			'STATE' => $page->state->value,
			'IS_FILTER_APPLIED' => $this->isFilterApplied($rawFilter),
			'AUDIT_ENABLED' => Configuration::isAuditEnabled(),
			// Switched off after it once collected events: the saved ones stay on screen.
			'AUDIT_HISTORY_AVAILABLE' => !Configuration::isAuditEnabled() && Configuration::wasAuditEverEnabled(),
		];

		$this->includeComponentTemplate();
	}

	private function loadFilterOptions(): array
	{
		try
		{
			$result = (new AuditQueryService())->getFilterOptions(Main\Engine\CurrentUser::get());

			return $result->isSuccess() ? $result->getData() : [];
		}
		catch (\Throwable)
		{
			return [];
		}
	}

	private function issuePageInstanceId(): string
	{
		return (new AuditPageInstanceTokenCodec())->issue(
			(int)Main\Engine\CurrentUser::get()->getId(),
		);
	}

	private function createNavigation(Main\Grid\Options $gridOptions): Main\UI\PageNavigation
	{
		$navigationParameters = $gridOptions->getNavParams(['nPageSize' => self::DEFAULT_PAGE_SIZE]);
		$pageSize = min(self::MAX_PAGE_SIZE, max(1, (int)$navigationParameters['nPageSize']));
		$navigation = new Main\UI\PageNavigation(self::NAVIGATION_ID);
		$navigation
			->setPageSize($pageSize)
			->allowAllRecords(false)
			->initFromUri()
		;

		return $navigation;
	}

	private function loadPage(array $rawFilter, Main\UI\PageNavigation $navigation): AuditPage
	{
		try
		{
			$filter = AuditFilter::createFromArray(
				$this->prepareFilterData($rawFilter, $navigation),
				\Bitrix\Disk\Controller\Audit::resolveAdministratorTimeZone(),
			);

			return (new AuditQueryService())->list($filter, Main\Engine\CurrentUser::get());
		}
		catch (\Throwable)
		{
			return new AuditPage(
				state: AuditState::TemporaryError,
				items: [],
				page: $navigation->getCurrentPage(),
				pageSize: $navigation->getPageSize(),
				total: 0,
			);
		}
	}

	private function prepareFilterData(array $rawFilter, Main\UI\PageNavigation $navigation): array
	{
		$filter = [
			'page' => $navigation->getCurrentPage(),
			'pageSize' => $navigation->getPageSize(),
		];

		$this->copyFilterValue($filter, 'from', $this->normalizeFilterDate($rawFilter['PERIOD_from'] ?? null));
		$this->copyFilterValue($filter, 'to', $this->normalizeFilterDate($rawFilter['PERIOD_to'] ?? null));
		$this->copyFilterValue($filter, 'eventCode', $this->normalizeList($rawFilter['EVENT_CODE'] ?? null));
		$this->copyFilterValue($filter, 'targetType', $this->normalizeList($rawFilter['TARGET_TYPE'] ?? null));
		$this->copyFilterValue($filter, 'storageId', $this->normalizeList($rawFilter['STORAGE_ID'] ?? null));
		$this->copyFilterValue($filter, 'channel', $this->normalizeList($rawFilter['CHANNEL'] ?? null));
		$this->copyFilterValue($filter, 'search', $rawFilter['FIND'] ?? null);
		$initiator = $this->prepareInitiatorFilter($rawFilter);
		if ($initiator !== null)
		{
			$filter['initiator'] = $initiator;
		}

		return $filter;
	}

	private function mergeRequestFilterData(array $storedFilter, mixed $requestFilter): array
	{
		if (!is_array($requestFilter))
		{
			return $storedFilter;
		}

		$currentFilter = [];
		foreach (self::FILTER_REQUEST_FIELDS as $field)
		{
			if (array_key_exists($field, $requestFilter))
			{
				$currentFilter[$field] = $requestFilter[$field];
			}
		}

		return $currentFilter;
	}

	private function prepareInitiatorFilter(array $rawFilter): ?array
	{
		$userId = $this->normalizeScalar($rawFilter['INITIATOR_USER'] ?? null);
		if ($userId !== null && preg_match('/^[0-9]+$/D', $userId) === 1 && (int)$userId > 0)
		{
			return [
				[
					'initiatorType' => AuditInitiator::TYPE_EMPLOYEE,
					'initiatorId' => $userId,
				],
				[
					'initiatorType' => AuditInitiator::TYPE_EXTERNAL_USER,
					'initiatorId' => $userId,
				],
			];
		}

		$initiatorType = $this->normalizeScalar($rawFilter['INITIATOR_TYPE'] ?? null);
		$initiatorId = $this->normalizeScalar($rawFilter['INITIATOR_ID'] ?? null);
		if (
			!in_array(
				$initiatorType,
				[AuditInitiator::TYPE_APPLICATION, AuditInitiator::TYPE_SYSTEM],
				true,
			)
		)
		{
			return null;
		}

		$initiator = [
			'initiatorType' => $initiatorType,
		];
		if ($initiatorId !== null)
		{
			$initiator['initiatorId'] = $initiatorId;
		}

		return [$initiator];
	}

	private function copyFilterValue(array &$filter, string $key, mixed $value): void
	{
		if ($value !== null && $value !== '' && $value !== [])
		{
			$filter[$key] = $value;
		}
	}

	private function normalizeFilterDate(mixed $value): ?string
	{
		$value = $this->normalizeScalar($value);
		if ($value === null)
		{
			return null;
		}

		return Main\Type\DateTime::createFromUserTime($value)
			->toUserTime()
			->format('Y-m-d H:i:s')
		;
	}

	private function normalizeList(mixed $value): ?array
	{
		if ($value === null || $value === '')
		{
			return null;
		}

		$values = is_array($value) ? $value : [$value];
		$values = array_values(array_filter(
			array_map($this->normalizeScalar(...), $values),
			static fn(?string $item): bool => $item !== null,
		));

		return $values === [] ? null : $values;
	}

	private function normalizeScalar(mixed $value): ?string
	{
		if (!is_scalar($value))
		{
			return null;
		}

		$value = trim((string)$value);

		return $value === '' ? null : $value;
	}

	private function getFilterDefinition(): array
	{
		$filterOptions = is_array($this->arParams['FILTER_OPTIONS'] ?? null)
			? $this->arParams['FILTER_OPTIONS']
			: [];

		return [
			[
				'id' => 'PERIOD',
				'name' => Main\Localization\Loc::getMessage('DISK_AUDIT_LOG_FILTER_PERIOD'),
				'type' => 'date',
				'default' => true,
			],
			[
				'id' => 'INITIATOR_USER',
				'name' => Main\Localization\Loc::getMessage('DISK_AUDIT_LOG_COLUMN_INITIATOR'),
				'type' => 'entity_selector',
				'default' => true,
				'params' => [
					'multiple' => false,
					'dialogOptions' => [
						'context' => 'DISK_AUDIT_LOG_INITIATOR_USER',
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
			[
				'id' => 'INITIATOR_TYPE',
				'name' => Main\Localization\Loc::getMessage('DISK_AUDIT_LOG_FILTER_INITIATOR_TYPE'),
				'type' => 'list',
				'default' => false,
				'items' => array_intersect_key(
					$this->normalizeFilterOptions($filterOptions['initiatorType'] ?? []),
					array_flip([AuditInitiator::TYPE_APPLICATION, AuditInitiator::TYPE_SYSTEM]),
				),
			],
			[
				'id' => 'INITIATOR_ID',
				'name' => Main\Localization\Loc::getMessage('DISK_AUDIT_LOG_FILTER_INITIATOR_ID'),
				'type' => 'string',
				'default' => false,
			],
			[
				'id' => 'EVENT_CODE',
				'name' => Main\Localization\Loc::getMessage('DISK_AUDIT_LOG_FILTER_EVENT'),
				'type' => 'list',
				'default' => true,
				'params' => ['multiple' => 'Y'],
				'items' => $this->normalizeFilterOptions($filterOptions['eventCode'] ?? []),
			],
			[
				'id' => 'TARGET_TYPE',
				'name' => Main\Localization\Loc::getMessage('DISK_AUDIT_LOG_FILTER_TARGET_TYPE'),
				'type' => 'list',
				'default' => true,
				'params' => ['multiple' => 'Y'],
				'items' => $this->normalizeFilterOptions($filterOptions['targetType'] ?? []),
			],
			[
				'id' => 'STORAGE_ID',
				'name' => Main\Localization\Loc::getMessage('DISK_AUDIT_LOG_FILTER_STORAGE_ID'),
				'type' => 'string',
				'default' => false,
			],
			[
				'id' => 'CHANNEL',
				'name' => Main\Localization\Loc::getMessage('DISK_AUDIT_LOG_FILTER_CHANNEL'),
				'type' => 'list',
				'default' => false,
				'params' => ['multiple' => 'Y'],
				'items' => $this->normalizeFilterOptions($filterOptions['channel'] ?? []),
			],
		];
	}

	private function getFilterPresets(): array
	{
		return [
			'disk_audit_all_events' => [
				'name' => Main\Localization\Loc::getMessage('DISK_AUDIT_LOG_PRESET_ALL_EVENTS'),
				'default' => true,
				'fields' => [],
			],
			'disk_audit_files_folders' => [
				'name' => Main\Localization\Loc::getMessage('DISK_AUDIT_LOG_PRESET_FILES_FOLDERS'),
				'fields' => [
					'EVENT_CODE' => [
						AuditEventCode::ObjectCreated->value,
						AuditEventCode::ObjectContentChanged->value,
						AuditEventCode::ObjectCopied->value,
						AuditEventCode::ObjectRenamed->value,
						AuditEventCode::ObjectMoved->value,
					],
					'TARGET_TYPE' => [
						AuditTarget::TYPE_FILE,
						AuditTarget::TYPE_FOLDER,
					],
				],
			],
			'disk_audit_deletion_trashcan' => [
				'name' => Main\Localization\Loc::getMessage('DISK_AUDIT_LOG_PRESET_DELETION_TRASHCAN'),
				'fields' => [
					'EVENT_CODE' => [
						AuditEventCode::ObjectMarkedDeleted->value,
						AuditEventCode::ObjectRestored->value,
						AuditEventCode::ObjectPermanentlyDeleted->value,
						AuditEventCode::TrashcanEmptied->value,
					],
					'TARGET_TYPE' => [
						AuditTarget::TYPE_FILE,
						AuditTarget::TYPE_FOLDER,
						AuditTarget::TYPE_TRASHCAN,
					],
				],
			],
			'disk_audit_link_access' => [
				'name' => Main\Localization\Loc::getMessage('DISK_AUDIT_LOG_PRESET_LINK_ACCESS'),
				'fields' => [
					'EVENT_CODE' => [
						AuditEventCode::RightsSubjectAdded->value,
						AuditEventCode::RightsSubjectRemoved->value,
						AuditEventCode::PublicLinkCreated->value,
						AuditEventCode::PublicLinkTtlChanged->value,
						AuditEventCode::PublicLinkPasswordEnabled->value,
						AuditEventCode::PublicLinkPasswordDisabled->value,
						AuditEventCode::PublicLinkAccessChanged->value,
						AuditEventCode::PublicLinkDisabled->value,
						AuditEventCode::UnifiedLinkAccessChanged->value,
					],
					'TARGET_TYPE' => [
						AuditTarget::TYPE_FILE,
						AuditTarget::TYPE_FOLDER,
						AuditTarget::TYPE_PUBLIC_LINK,
						AuditTarget::TYPE_UNIFIED_LINK,
						AuditTarget::TYPE_RIGHTS,
					],
				],
			],
			'disk_audit_rights' => [
				'name' => Main\Localization\Loc::getMessage('DISK_AUDIT_LOG_PRESET_RIGHTS'),
				'fields' => [
					'EVENT_CODE' => [
						AuditEventCode::RightsSubjectChanged->value,
						AuditEventCode::RightsInheritanceEnabled->value,
						AuditEventCode::RightsInheritanceDisabled->value,
						AuditEventCode::RightsCascadeChanged->value,
					],
					'TARGET_TYPE' => [
						AuditTarget::TYPE_FILE,
						AuditTarget::TYPE_FOLDER,
						AuditTarget::TYPE_RIGHTS,
					],
				],
			],
			'disk_audit_history_management' => [
				'name' => Main\Localization\Loc::getMessage('DISK_AUDIT_LOG_PRESET_HISTORY_MANAGEMENT'),
				'fields' => [
					'EVENT_CODE' => [
						AuditSettingsEventFactory::EVENT_ENABLED,
						AuditSettingsEventFactory::EVENT_DISABLED,
						AuditSettingsEventFactory::EVENT_RETENTION_CHANGED,
						AuditEventCode::AuditLogOpened->value,
					],
					'TARGET_TYPE' => [
						AuditTarget::TYPE_AUDIT_SETTINGS,
						AuditTarget::TYPE_AUDIT_LOG,
					],
				],
			],
		];
	}

	private function createFilterOptions(array $filterPresets): Main\UI\Filter\Options
	{
		$filterOptions = new Main\UI\Filter\Options(self::FILTER_ID, $filterPresets);
		$savedPresets = $filterOptions->getPresets();
		$mergedPresets = $this->mergeFilterPresets($filterPresets, $savedPresets);
		$options = $filterOptions->getOptions();
		$deletedPresets = is_array($options['deleted_presets'] ?? null)
			? $options['deleted_presets']
			: []
		;
		$mergedDeletedPresets = array_values(array_diff($deletedPresets, array_keys($filterPresets)));

		if ($savedPresets !== $mergedPresets || $deletedPresets !== $mergedDeletedPresets)
		{
			$filterOptions->setPresets($mergedPresets);
			$filterOptions->setDeletedPresets($mergedDeletedPresets);
			$filterOptions->save();
		}

		return $filterOptions;
	}

	private function mergeFilterPresets(array $systemPresets, mixed $savedPresets): array
	{
		return $systemPresets + (is_array($savedPresets) ? $savedPresets : []);
	}

	private function normalizeFilterOptions(mixed $options): array
	{
		if (!is_array($options))
		{
			return [];
		}

		$result = [];
		foreach ($options as $key => $option)
		{
			if (is_string($key) && is_string($option))
			{
				$result[$key] = $option;
			}
			elseif (is_string($option))
			{
				$result[$option] = $this->localizeValue($option);
			}
		}

		return $result;
	}

	private function getGridHeaders(): array
	{
		return [
			[
				'id' => 'OCCURRED_AT',
				'name' => Main\Localization\Loc::getMessage('DISK_AUDIT_LOG_COLUMN_OCCURRED_AT'),
				'default' => true,
			],
			[
				'id' => 'OBJECT',
				'name' => Main\Localization\Loc::getMessage('DISK_AUDIT_LOG_COLUMN_OBJECT'),
				'default' => true,
			],
			[
				'id' => 'EVENT',
				'name' => Main\Localization\Loc::getMessage('DISK_AUDIT_LOG_COLUMN_EVENT'),
				'default' => true,
			],
			[
				'id' => 'INITIATOR',
				'name' => Main\Localization\Loc::getMessage('DISK_AUDIT_LOG_COLUMN_INITIATOR'),
				'default' => true,
			],
			[
				'id' => 'STORAGE',
				'name' => Main\Localization\Loc::getMessage('DISK_AUDIT_LOG_COLUMN_STORAGE'),
				'default' => true,
			],
			[
				'id' => 'CONTEXT',
				'name' => Main\Localization\Loc::getMessage('DISK_AUDIT_LOG_COLUMN_CONTEXT'),
				'default' => false,
				// A task or a deal is named in full; the other columns keep their widths.
				'width' => 240,
			],
			[
				'id' => 'CHANNEL',
				'name' => Main\Localization\Loc::getMessage('DISK_AUDIT_LOG_COLUMN_CHANNEL'),
				'default' => false,
			],
		];
	}

	/**
	 * Converts audit list items to safe main.ui.grid rows.
	 *
	 * @param AuditListItem[] $items
	 * @return array[]
	 */
	public function formatRows(array $items, bool $withCurrentContextNames = true): array
	{
		$culture = Main\Context::getCurrent()->getCulture();
		$dateTimeFormat = $culture->get('DAY_MONTH_FORMAT') . ' ' . $culture->get('SHORT_TIME_FORMAT');
		$avatars = $this->loadInitiatorAvatars($items);
		$contextNames = $withCurrentContextNames ? $this->createContextNameResolver()->resolve($items) : [];
		$copyIdLabel = (string)Main\Localization\Loc::getMessage('DISK_AUDIT_LOG_ACTION_COPY_EVENT_ID');

		return array_map(
			function (AuditListItem $item) use ($dateTimeFormat, $avatars, $contextNames, $copyIdLabel): array {
				$initiator = $item->initiatorName;
				if ($initiator === '' || $item->initiatorType === AuditInitiator::TYPE_SYSTEM)
				{
					$initiator = $this->localizeValue($item->initiatorType);
				}
				// The current name, the way the card shows it; the snapshot when the entity is gone.
				$context = $contextNames[$item->id] ?? $item->contextEntityName ?? $this->localizeContextType($item);
				$eventLabel = $this->localizeEvent($item);
				$occurredAt = (clone $item->occurredAt)->toUserTime();
				$rowAriaLabel = Main\Localization\Loc::getMessage(
					'DISK_AUDIT_LOG_ROW_OPEN_ARIA',
					[
						'#ACTION#' => $eventLabel,
						'#OBJECT#' => $this->getTargetDisplayName($item),
					],
				);
				if (!is_string($rowAriaLabel) || $rowAriaLabel === '')
				{
					$rowAriaLabel = $eventLabel;
				}

				return [
					'id' => $item->id,
					'columns' => [
						'OCCURRED_AT' => htmlspecialcharsbx(FormatDate($dateTimeFormat, $occurredAt)),
						'OBJECT' => $this->renderObjectCell($item),
						'EVENT' => '<span class="disk-audit-log__event">' . htmlspecialcharsbx($eventLabel) . '</span>',
						'INITIATOR' => $this->renderPersonCell(
							$item,
							$initiator,
							$avatars[$item->initiatorId] ?? null,
						),
						'STORAGE' => $this->renderStorageCell($item),
						'CONTEXT' => htmlspecialcharsbx($context),
						'CHANNEL' => $this->renderChannelCell($item->channel),
					],
					'actions' => [
						[
							'text' => $copyIdLabel,
							'onclick' => 'BX.Disk.AuditLog.copyEventId(' . (int)$item->id . ')',
						],
					],
					'attrs' => [
						'data-audit-event-row-id' => (string)$item->id,
						'tabindex' => '0',
						'aria-label' => $rowAriaLabel,
					],
				];
			},
			$items,
		);
	}

	/** @param AuditListItem[] $items */
	private function loadInitiatorAvatars(array $items): array
	{
		$userIds = [];
		foreach ($items as $item)
		{
			if (
				in_array(
					$item->initiatorType,
					[AuditInitiator::TYPE_EMPLOYEE, AuditInitiator::TYPE_EXTERNAL_USER],
					true,
				)
				&& preg_match('/^[0-9]+$/D', $item->initiatorId) === 1
			)
			{
				$userIds[(int)$item->initiatorId] = true;
			}
		}

		if ($userIds === [])
		{
			return [];
		}

		// Two queries for the whole page: the photo ids of the listed users, then their
		// files in one batch, so a page of avatars is not a file lookup per person. A user
		// without a picture of their own keeps the portal placeholder, the same avatar the
		// product shows for them everywhere else.
		// Photos are an extra to the page: a failing lookup leaves the marks, not a broken journal.
		try
		{
			$photoIds = Main\UserTable::getList([
				'select' => ['ID', 'PERSONAL_PHOTO'],
				'filter' => ['@ID' => array_keys($userIds)],
			])->fetchAll();
			Avatar::preload(array_column($photoIds, 'PERSONAL_PHOTO'));

			$avatars = [];
			foreach ($photoIds as $row)
			{
				$avatars[(string)$row['ID']] = Avatar::getPerson(
					(int)$row['PERSONAL_PHOTO'],
					self::AVATAR_SIZE,
					self::AVATAR_SIZE,
				);
			}
		}
		catch (\Throwable)
		{
			return [];
		}

		return $avatars;
	}

	private function renderPersonCell(AuditListItem $item, string $name, ?string $imageSource): string
	{
		if ($name === '')
		{
			return '';
		}

		return '<span class="disk-audit-log__person">'
			. $this->renderInitiatorMark($item, $name, $imageSource)
			. '<span class="disk-audit-log__person-name">' . htmlspecialcharsbx($name) . '</span>'
			. '</span>'
		;
	}

	/**
	 * Every row keeps a mark of the same size in front of the name: the uploaded picture
	 * when there is one, initials for a person without one and a glyph for a machine.
	 */
	private function renderInitiatorMark(AuditListItem $item, string $name, ?string $imageSource): string
	{
		$initiatorType = $item->initiatorType;

		if ($imageSource !== null)
		{
			return '<img class="disk-audit-log__person-avatar" src="' . htmlspecialcharsbx($imageSource) . '"'
				. ' alt="" loading="lazy" aria-hidden="true">'
			;
		}

		$icon = self::INITIATOR_ICONS[$initiatorType] ?? null;
		if ($icon !== null)
		{
			$machineClass = $initiatorType === AuditInitiator::TYPE_APPLICATION ? '--application' : '--system';

			return '<span class="disk-audit-log__person-avatar --machine ' . $machineClass . '" aria-hidden="true">'
				. '<span class="ui-icon-set --' . $icon . '"></span>'
				. '</span>'
			;
		}

		return '<img class="disk-audit-log__person-avatar" src="'
			. htmlspecialcharsbx(Avatar::getDefaultPerson())
			. '" alt="" loading="lazy" aria-hidden="true">'
		;
	}

	/**
	 * The channel reads as a quiet tag: its glyph and a short name that fits the column.
	 * Keep in step with CHANNEL_ICONS in event-card.ts.
	 */
	private const CHANNEL_ICONS = [
		'web' => 'browser',
		'mobile' => 'o-mobile',
		'desktop' => 'o-screen',
		'api' => 'o-webhook',
		'bizproc' => 'o-business-process',
		'system' => 'o-settings',
	];

	private function renderChannelCell(string $channel): string
	{
		$name = Main\Localization\Loc::getMessage('DISK_AUDIT_LOG_CHANNEL_SHORT_' . strtoupper($channel));
		if (!is_string($name) || $name === '')
		{
			$name = $this->localizeValue($channel);
		}

		$icon = self::CHANNEL_ICONS[$channel] ?? 'browser';

		return '<span class="disk-audit-log__channel">'
			. '<span class="ui-icon-set --' . $icon . ' disk-audit-log__channel-icon" aria-hidden="true"></span>'
			. '<span class="disk-audit-log__channel-name">' . htmlspecialcharsbx($name) . '</span>'
			. '</span>'
		;
	}

	private function renderStorageCell(AuditListItem $item): string
	{
		$name = (string)$item->storageName;
		if ($name === '')
		{
			return '';
		}

		$initials = $this->getInitials($name);
		$escapedName = htmlspecialcharsbx($name);
		if ($initials === '')
		{
			return $escapedName;
		}

		$tint = $this->getTint((int)$item->storageId);

		return '<span class="disk-audit-log__person">'
			. '<span class="disk-audit-log__storage-initials --tint-' . $tint . '" aria-hidden="true">'
			. htmlspecialcharsbx($initials)
			. '</span>'
			. '<span class="disk-audit-log__person-name">' . $escapedName . '</span>'
			. '</span>'
		;
	}

	/** The tint follows the entity id, not its name, so it survives a rename. */
	private function getTint(int $entityId): int
	{
		return abs($entityId) % self::TINT_COUNT + 1;
	}

	/** Mirrors AvatarBase in ui.avatar: up to two leading letters. */
	private function getInitials(string $title): string
	{
		$words = array_values(array_filter(
			(array)preg_split('/[\s,]+/u', $title, -1, PREG_SPLIT_NO_EMPTY),
			static fn(string $word): bool => preg_match('/^[\p{L}\p{N}]/u', $word) === 1,
		));
		if ($words === [])
		{
			return '';
		}

		$initials = count($words) > 1
			? mb_substr($words[0], 0, 1) . mb_substr($words[1], 0, 1)
			: mb_substr($words[0], 0, 1)
		;

		return mb_strtoupper($initials);
	}

	private function renderObjectCell(AuditListItem $item): string
	{
		$iconModifiers = $this->getObjectIconModifiers($item->targetType, $item->targetName);
		$name = htmlspecialcharsbx($this->getTargetDisplayName($item));
		if ($iconModifiers === null)
		{
			return $name;
		}

		return '<span class="disk-audit-log__object">'
			. '<span class="disk-audit-log__object-icon ui-icon-set ' . $iconModifiers . '" aria-hidden="true"></span>'
			. '<span class="disk-audit-log__object-name" title="' . $name . '">' . $name . '</span>'
			. '</span>'
		;
	}

	/**
	 * A target that keeps no name a person would recognise is named by its type; everything
	 * else shows the stored name of the object the action was applied to.
	 */
	private function getTargetDisplayName(AuditListItem $item): string
	{
		return in_array($item->targetType, self::NAMELESS_TARGETS, true)
			? $this->localizeValue($item->targetType)
			: $item->targetName
		;
	}

	/**
	 * Files and folders take the colourful ui.icon-set.disk, which needs --fixed-color to
	 * keep its own colours; the rest take a monochrome glyph from ui.icon-set.outline.
	 * Older events carry a link or the rights as the target under the file name,
	 * so a known extension still draws that file.
	 */
	private function getObjectIconModifiers(string $targetType, string $targetName): ?string
	{
		if ($targetType === AuditTarget::TYPE_FOLDER)
		{
			return '--fixed-color --folder';
		}

		$fileIcon = self::FILE_ICON_CLASSES[mb_strtolower((string)pathinfo($targetName, PATHINFO_EXTENSION))] ?? null;
		if ($targetType === AuditTarget::TYPE_FILE)
		{
			return '--fixed-color --' . ($fileIcon ?? 'empty');
		}

		if ($fileIcon !== null && in_array($targetType, self::OBJECT_NAMED_TARGETS, true))
		{
			return '--fixed-color --' . $fileIcon;
		}

		$icon = self::NON_OBJECT_TARGET_ICONS[$targetType] ?? null;

		return $icon === null ? null : '--' . $icon;
	}

	/**
	 * An action on a file or a folder names what it was done to; the rest keep the name of the
	 * event. Keep in step with getEventTitle() in event-card.ts.
	 */
	private function localizeEvent(AuditListItem $item): string
	{
		if (in_array($item->targetType, [AuditTarget::TYPE_FILE, AuditTarget::TYPE_FOLDER], true))
		{
			$message = Main\Localization\Loc::getMessage(
				$this->getValueMessageKey($item->eventCode) . '_' . strtoupper($item->targetType),
			);
			if (is_string($message) && $message !== '')
			{
				return $message;
			}
		}

		return $this->localizeValue($item->eventCode);
	}

	/** A deal is named a deal, the way the product calls it; CRM names it as D_<id>. */
	private function localizeContextType(AuditListItem $item): string
	{
		if (($item->contextEntityType ?? $item->contextType) === 'crm' && preg_match('/^D_[1-9][0-9]*$/D', (string)$item->contextEntityId) === 1)
		{
			$message = Main\Localization\Loc::getMessage('DISK_AUDIT_LOG_CONTEXT_DEAL');
			if (is_string($message) && $message !== '')
			{
				return $message;
			}
		}

		return $this->localizeValue($item->contextType);
	}

	private function getValueMessageKey(string $value): string
	{
		return 'DISK_AUDIT_LOG_VALUE_' . strtoupper(preg_replace('/[^a-z0-9]+/i', '_', $value));
	}

	private function localizeValue(string $value): string
	{
		$message = Main\Localization\Loc::getMessage($this->getValueMessageKey($value));

		return is_string($message) && $message !== '' ? $message : $value;
	}

	private function isFilterApplied(array $rawFilter): bool
	{
		foreach ($rawFilter as $key => $value)
		{
			if ($key !== 'FILTER_ID' && $value !== null && $value !== '' && $value !== [])
			{
				return true;
			}
		}

		return false;
	}
}
