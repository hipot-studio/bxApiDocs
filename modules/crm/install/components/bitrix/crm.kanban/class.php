<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\Crm\Activity\TodoPingSettingsProvider;
use Bitrix\Crm\Integration\Analytics\Builder\FunnelAnalytics;
use Bitrix\Crm\Integration\Analytics\Dictionary;
use Bitrix\Crm\Integration\IntranetManager;
use Bitrix\Crm\Integration\PullManager;
use Bitrix\Crm\Kanban;
use Bitrix\Crm\Kanban\Desktop;
use Bitrix\Crm\Kanban\Entity\Dynamic;
use Bitrix\Crm\Kanban\EntityActivityCounter;
use Bitrix\Crm\Kanban\ViewMode;
use Bitrix\Crm\Order;
use Bitrix\Crm\Order\OrderDealSynchronizer;
use Bitrix\Crm\PhaseSemantics;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\Display\Field\UserField;
use Bitrix\Crm\Service\Timeline;
use Bitrix\Crm\Service\UserPermissions\ConfigurationCallSite;
use Bitrix\Crm\Service\UserPermissions\ConfigurationScope;
use Bitrix\Crm\Settings\CounterSettings;
use Bitrix\Main\Application;
use Bitrix\Main\DB\TransactionException;
use Bitrix\Main\Entity\AddResult;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ModuleManager;
use Bitrix\Main\Result;
use Bitrix\Main\Web\Uri;
use Bitrix\Sale;
use Bitrix\Sale\Internals\StatusLangTable;
use Bitrix\Sale\Internals\StatusTable;

class CrmKanbanComponent extends CBitrixComponent
{
	protected const OPTION_CATEGORY = 'crm';
	protected const OPTION_NAME_HIDE_CONTACT_CENTER = 'kanban_cc_hide';
	protected const OPTION_NAME_HIDE_REST_DEMO = 'kanban_rest_hide';
	protected const COLUMN_NAME_DELETED = 'DELETED';
	private const MODE_NOT_AVAILABLE_ERROR_CODE = 'MODE_NOT_AVAILABLE';

	/** @var Desktop */
	protected Kanban $kanban;
	protected string $statusKey;

	protected int $blockPage = 1;

	protected int $currentUserID = 0;
	protected array $componentParams = [];
	protected bool $needPrepareColumns = false;

	public function onPrepareComponentParams($arParams): array
	{
		if (!Loader::includeModule('crm'))
		{
			return $arParams;
		}

		$arParams['HIDE_CC'] = CUserOptions::getOption(
			static::OPTION_CATEGORY,
			static::OPTION_NAME_HIDE_CONTACT_CENTER,
		);

		if (!isset($arParams['ADDITIONAL_FILTER']) || !is_array($arParams['ADDITIONAL_FILTER']))
		{
			$arParams['ADDITIONAL_FILTER'] = [];
		}
		if (!isset($arParams['EXTRA']) || !is_array($arParams['EXTRA']))
		{
			$arParams['EXTRA'] = [];
		}
		$arParams['API_VERSION'] = Kanban\ApiVersionManager::getInstance()->getVersion();
		$arParams['PAGE'] = (int)($arParams['PAGE'] ?? null);
		$arParams['ONLY_COLUMNS'] = ($arParams['ONLY_COLUMNS'] ?? 'N')  !== 'Y' ? 'N' : 'Y';
		$arParams['ONLY_ITEMS'] = ($arParams['ONLY_ITEMS'] ?? 'N')  !== 'Y' ? 'N' : 'Y';
		$arParams['EMPTY_RESULT'] = ($arParams['EMPTY_RESULT'] ?? 'N') !== 'Y' ? 'N' : 'Y';
		$arParams['IS_AJAX'] = ($arParams['IS_AJAX'] ?? 'N') !== 'Y' ? 'N' : 'Y';
		$arParams['GET_AVATARS'] = ($arParams['GET_AVATARS'] ?? 'N') !== 'Y' ? 'N' : 'Y';
		$arParams['FORCE_FILTER'] = ($arParams['FORCE_FILTER'] ?? 'N') !== 'Y' ? 'N' : 'Y';
		$arParams['VIEW_MODE'] = ViewMode::normalize((string)($arParams['VIEW_MODE'] ?? ''));
		$arParams['FULL_CONFIG'] = ($arParams['FULL_CONFIG'] ?? 'Y') !== 'N' ? 'Y' : 'N';
		$arParams['PATH_TO_USER'] = $arParams['PATH_TO_USER'] ?? '/company/personal/user/#user_id#/';
		$arParams['PATH_TO_MERGE'] = $arParams['PATH_TO_MERGE'] ?? '';
		$arParams['PATH_TO_DEAL_KANBANCATEGORY'] = $arParams['PATH_TO_DEAL_KANBANCATEGORY'] ?? '';
		$arParams['HEADER_SECTIONS'] = $arParams['HEADER_SECTIONS'] ?? [];
		$arParams['PATH_TO_IMPORT'] = $arParams['PATH_TO_IMPORT'] ?? '';
		$arParams['~NAME_TEMPLATE'] = $arParams['~NAME_TEMPLATE'] ?? null;
		$arParams['FILTER_PRESET_ID'] = is_string($arParams['FILTER_PRESET_ID'] ?? null)
			? $arParams['FILTER_PRESET_ID']
			: null
		;
		$arParams['FILTER_FIELDS'] = is_array($arParams['FILTER_FIELDS'] ?? null)
			? $arParams['FILTER_FIELDS']
			: []
		;

		return $arParams;
	}

	protected function init(): bool
	{
		if (!Loader::includeModule('crm'))
		{
			ShowError(Loc::getMessage('CRM_KANBAN_CRM_NOT_INSTALLED'));

			return false;
		}

		$type = mb_strtoupper($this->arParams['ENTITY_TYPE'] ?? '');
		$params = [
			'NAME_TEMPLATE' => ($this->arParams['~NAME_TEMPLATE'] ?? ''),
			'PATH_TO_IMPORT' => ($this->arParams['PATH_TO_IMPORT'] ?? ''),
			'ONLY_COLUMNS' => ($this->arParams['ONLY_COLUMNS'] ?? 'N'),
			'ONLY_ITEMS' => ($this->arParams['ONLY_ITEMS'] ?? 'N'),
			'VIEW_MODE' => $this->arParams['VIEW_MODE'],
			'CATEGORY_ID' => (int)($this->arParams['EXTRA']['CATEGORY_ID'] ?? 0),
			'USE_ITEM_PLANNER' => ($this->arParams['USE_ITEM_PLANNER'] ?? 'N'),
			'SKIP_COLUMN_COUNT_CHECK' => ($this->componentParams['SKIP_COLUMN_COUNT_CHECK'] ?? 'N'),
			'CUSTOM_SECTION_CODE' => ($this->arParams['EXTRA']['CUSTOM_SECTION_CODE'] ?? null),
			'API_VERSION' => $this->arParams['API_VERSION'],
			'FILTER_PRESET_ID' => $this->arParams['FILTER_PRESET_ID'],
			'FILTER_FIELDS' => $this->getFilterFields(),
		];
		$this->kanban = Desktop::getInstance($type, $params);

		if (!$this->getKanban()->isSupported())
		{
			ShowError(Loc::getMessage('CRM_KANBAN_NOT_SUPPORTED'));

			return false;
		}

		$entityTypeId = $this->getEntityTypeId();
		$skipCheckPermissions = ($entityTypeId === CCrmOwnerType::Activity);
		$categoryId = $this->getEntity()->getCategoryId();

		if (!$skipCheckPermissions)
		{
			$entityTypePermissions = $this->getEntity()
				->getUserPermissions()
				->entityType()
			;

			$isReadPermissions = ($categoryId >= 0)
				? $entityTypePermissions->canReadItemsInCategory($entityTypeId, $categoryId)
				: $entityTypePermissions->canReadItems($entityTypeId)
			;

			if (!$isReadPermissions)
			{
				return false;
			}
		}

		$this->componentParams =  $this->getKanban()->getComponentParams();

		$this->arParams['ENTITY_TYPE_CHR'] = $this->componentParams['ENTITY_TYPE_CHR'];
		$this->arParams['ENTITY_TYPE_INT'] = $this->componentParams['ENTITY_TYPE_INT'];
		$this->arParams['ENTITY_TYPE_INFO'] = $this->componentParams['ENTITY_TYPE_INFO'];
		$this->arParams['IS_DYNAMIC_ENTITY'] = $this->componentParams['IS_DYNAMIC_ENTITY'];
		$this->arParams['ENTITY_PATH'] = $this->componentParams['ENTITY_PATH'];
		$this->arParams['EDITOR_CONFIG_ID'] = $this->componentParams['EDITOR_CONFIG_ID'];
		$this->arParams['HIDE_REST'] = $this->componentParams['HIDE_REST'];
		$this->arParams['REST_DEMO_URL'] = $this->componentParams['REST_DEMO_URL'];

		if ($this->arParams['PAGE'] > 1)
		{
			$this->blockPage = $this->arParams['PAGE'];
		}

		if (
			!empty($this->arParams['HEADERS_SECTIONS'])
			&& is_array($this->arParams['HEADERS_SECTIONS'])
		)
		{
			$this->prepareHeaderSections();
		}

		$userId = Container::getInstance()->getContext()->getUserId();
		$this->currentUserID = $userId;
		$this->statusKey = $this->componentParams['STATUS_KEY'];

		$this->arParams['USER_ID'] = $userId;
		$this->arParams['LAYOUT_CURRENT_USER'] = Timeline\Layout\User::current()->toArray();
		$this->arParams['PING_SETTINGS'] = (new TodoPingSettingsProvider(
			$entityTypeId,
			$categoryId
		))->fetchForJsComponent();
		$this->arParams['CURRENCY'] = $this->componentParams['CURRENCY'];
		$this->arParams['PATH_TO_IMPORT'] = $this->componentParams['PATH_TO_IMPORT'];

		return true;
	}

	/**
	 * Get stages description for current entity.
	 */
	protected function getStatuses(bool $isClear = false): array
	{
		return $this->getKanban()->getStatuses($isClear);
	}

	protected function getColumns(bool $clear = false, bool $withoutCache = false, array $params = []): array
	{
		$params['ONLY_ITEMS'] = $this->arParams['ONLY_ITEMS'];
		$params['FORCE_FILTER'] = $this->arParams['FORCE_FILTER'];
		$params['VIEW_MODE'] = ($this->arParams['VIEW_MODE'] ?? ViewMode::MODE_STAGES);
		$params = array_merge($params, $this->getLiveFilterParams());

		return $this->getKanban()->getColumns($clear, $withoutCache, $params);
	}

	public static function prepareColumnForClient(
		array $column,
		bool $canSort,
	): array
	{
		return [
			'id' => $column['id'],
			'total' => (int)$column['count'],
			'color' => $column['color'],
			'name' => htmlspecialcharsback($column['name']),
			'canSort' => $canSort,
			'canAddItem' => $column['canAddItem'],
			'data' => [
				'sort' => $column['sort'],
				'real_sort' => $column['real_sort'],
				'type' => $column['type'],
				'description' => (string)($column['description'] ?? ''),
				'sum' => round($column['total'] ?? 0),
				'sum_init' => 0,
				'sum_format' => $column['total_format'] ?? null,
				'blockedIncomingMoving' => ($column['blockedIncomingMoving'] ?? false),
				'hiddenTotalSum' => ($column['hiddenTotalSum'] ?? false),
				'currencyFormat' => ($column['currencyFormat'] ?? false),
			],
		];
	}

	protected function getItems(array $filter = []): array
	{
		$params = $this->getKanban()->getItems(
			$filter,
			$this->blockPage,
			[
				'filter' => $this->getLiveFilterParams(),
			]
		);
		if ($params['RESTRICTED_VALUE_CLICK_CALLBACK'])
		{
			$this->arResult['RESTRICTED_VALUE_CLICK_CALLBACK'] = $params['RESTRICTED_VALUE_CLICK_CALLBACK'];
		}

		return $params['ITEMS'];
	}

	protected function getLiveFilterParams(): array
	{
		return [
			'FILTER_PRESET_ID' => $this->arParams['FILTER_PRESET_ID'],
			'FILTER_FIELDS' => $this->getFilterFields(),
		];
	}

	private function getFilterFields(): array
	{
		$filterFields = $this->arParams['FILTER_FIELDS'] ?? [];
		if (!is_array($filterFields))
		{
			return [];
		}

		return $this->unescapeFilterFieldValues($filterFields);
	}

	private function unescapeFilterFieldValues(array $filterFields): array
	{
		foreach ($filterFields as $fieldId => $fieldValue)
		{
			if (is_array($fieldValue))
			{
				$filterFields[$fieldId] = $this->unescapeFilterFieldValues($fieldValue);
			}
			elseif (is_string($fieldValue))
			{
				$filterFields[$fieldId] = $this->unescapeFilterFieldValue($fieldValue);
			}
		}

		return $filterFields;
	}

	private function unescapeFilterFieldValue(string $fieldValue): string
	{
		if (strpos($fieldValue, '&quot;') === false)
		{
			return $fieldValue;
		}

		$unescapedValue = str_replace('&quot;', '"', $fieldValue);
		$firstChar = substr(trim($unescapedValue), 0, 1);
		if ($firstChar !== '[' && $firstChar !== '{')
		{
			return $fieldValue;
		}

		return $unescapedValue;
	}

	protected function isSkipColumnCountCheck(): bool
	{
		return ($this->arParams['SKIP_COLUMN_COUNT_CHECK'] ?? 'N') === 'Y';
	}

	protected function getEntityTypeId(): int
	{
		return $this->getEntity()->getTypeId();
	}

	protected function isStageDescriptionSupported(): bool
	{
		$entityTypeId = $this->getEntityTypeId();

		return in_array(
			$entityTypeId,
			[
				CCrmOwnerType::Lead,
				CCrmOwnerType::Deal,
				CCrmOwnerType::Quote,
				CCrmOwnerType::SmartInvoice,
			],
			true,
		) || CCrmOwnerType::isPossibleDynamicTypeId($entityTypeId);
	}

	/**
	 * Returns error array if ACTIVITIES mode is no longer available for the current entity, null otherwise.
	 * Used to protect AJAX calls when the admin disables counters after the user has opened the Activities view.
	 */
	private function checkActivitiesModeAvailability(): ?array
	{
		if (($this->arParams['VIEW_MODE'] ?? '') !== ViewMode::MODE_ACTIVITIES)
		{
			return null;
		}

		$factory = Container::getInstance()->getFactory($this->getEntityTypeId());
		if (
			!($factory instanceof \Bitrix\Crm\Service\Factory\Dynamic)
			|| $factory->isCountersEnabled()
		)
		{
			return null;
		}

		return [
			'ERROR' => Loc::getMessage('CRM_KANBAN_MODE_NOT_AVAILABLE'),
			'ERROR_CODE' => self::MODE_NOT_AVAILABLE_ERROR_CODE,
		];
	}

	/**
	 * Make some actions (set, update, etc.).
	 * @return void
	 */
	protected function processRequestActions(): void
	{
		$context = Application::getInstance()->getContext();
		$request = $context->getRequest();
		$action = $request->get('action');
		$id = $request->get('entity_id');

		// some actions for editor
		if ($action === 'get' && check_bitrix_sessid())
		{
			$ajaxParams = (array)$request->get('ajaxParams');
			if (isset($ajaxParams['editorReset']))
			{
				$this->resetCardFields();
			}
			if (isset($ajaxParams['editorSetCommon']))
			{
				$this->setCommonCardFields();
			}
		}

		//update fields
		if ($action && $id && check_bitrix_sessid())
		{
			$statuses = $this->getStatuses();
			$status = $request->get('status');
			$ids = (is_array($id) ? $id : [$id]);
			// skip action delete
			if ($action === 'status' && $status === static::COLUMN_NAME_DELETED)
			{
				$ids = [];
			}

			foreach ($ids as $id)
			{
				//delete
				if ($action === 'status' && $status === static::COLUMN_NAME_DELETED)
				{
					$this->actionDelete($id);
				}
				//change status / stage
				if ($action === 'status' && isset($statuses[$status]))
				{
					$result = $this->actionUpdateEntityStatus($id, $status, $statuses);
					if (!$result->isSuccess())
					{
						foreach ($result->getErrors() as $error)
						{
							$this->arResult['ERROR'] = $error->getMessage();
							$this->arResult['ERROR_CODE'] = $error->getCode();
						}
					}
					else
					{
						$viewMode = $this->arParams['VIEW_MODE'] ?? '';
						$this->arResult['ITEMS']['isShouldUpdateCard'] = $viewMode === ViewMode::MODE_DEADLINES;
					}
				}
			}

			if ($this->needPrepareColumns)
			{
				$this->getColumns(true);
				$this->needPrepareColumns = false;
			}

			if ($this->arParams['IS_AJAX'] !== 'Y')
			{
				$uri = new Uri($request->getRequestUri());
				LocalRedirect($uri->deleteParams(['action', 'entity_id', 'status'])->getUri());
			}
		}
	}

	public function executeComponent()
	{
		if (!$this->init())
		{
			return [];
		}

		$this->arResult['ITEMS'] = $this->componentParams['ITEMS'];
		$this->arResult['ADMINS'] = $this->componentParams['ADMINS'];
		$this->arResult['MORE_FIELDS'] = $this->componentParams['MORE_FIELDS'];
		$this->arResult['MORE_EDIT_FIELDS'] = $this->componentParams['MORE_EDIT_FIELDS'];
		$this->arResult['CATEGORIES'] = $this->componentParams['CATEGORIES'];
		$this->arResult['FIELDS_SECTIONS'] = $this->componentParams['FIELDS_SECTIONS'] ?? null;
		$this->arResult['SHOW_ERROR_COUNTER_BY_ACTIVITY_RESPONSIBLE'] = $this->showErrorCounterByActivityResponsible();
		$this->arResult['SKIP_COLUMN_COUNT_CHECK'] = $this->isSkipColumnCountCheck();
		$this->arResult['USE_ITEM_PLANNER'] = ($this->arParams['USE_ITEM_PLANNER'] ?? 'N') === 'Y';
		$this->arResult['USE_PUSH_CRM'] = ($this->arParams['USE_PUSH_CRM'] ?? 'Y') === 'Y';
		$this->arResult['PERFORMANCE'] = $this->arParams['PERFORMANCE'] ?? [];
		$this->arResult['STAGE_ANALYTICS'] = $this->getStageAnalyticsLabels();
		$this->arResult['COPILOT_NAME'] = \Bitrix\Crm\Integration\AI\AIManager::getCopilotName();
		$this->arResult['IS_STAGE_DESCRIPTION_SUPPORTED'] = $this->isStageDescriptionSupported();

		$stub = $this->getStub();
		if (!empty($stub))
		{
			$this->arResult['STUB'] = $stub;
		}

		$context = Application::getInstance()->getContext();
		$request = $context->getRequest();
		$action = $request->get('action');

		//new actions format
		if ($action && is_callable([$this, 'action' . $action]))
		{
			if (!check_bitrix_sessid())
			{
				return [
					'ERROR' => Loc::getMessage('CRM_KANBAN_ERROR_SESSION_EXPIRED'),
					'FATAL' => true,
				];
			}

			$modeError = $this->checkActivitiesModeAvailability();
			if ($modeError !== null)
			{
				return $modeError;
			}

			return $this->{'action' . $action}();
		}

		$modeError = $this->checkActivitiesModeAvailability();
		if ($modeError !== null)
		{
			$this->arResult['ERROR'] = $modeError['ERROR'];
			$this->arResult['ERROR_CODE'] = $modeError['ERROR_CODE'];

			if ($this->arParams['IS_AJAX'] === 'Y')
			{
				return $this->arResult;
			}
		}

		$this->processRequestActions();
		$entity = $this->getEntity();

		if ($this->arParams['EMPTY_RESULT'] !== 'Y')
		{
			$userPermissions = $this->getKanban()->getCurrentUserPermissions();

			$this->arResult['IS_LOCKED_ENTITY'] = ($this->arParams['IS_LOCKED_ENTITY'] ?? 'N') === 'Y';
			$this->arResult['ACCESS_CONFIG_PERMS'] = $this->areStagesEditable();
			$this->arResult = array_merge($this->arResult, $entity->getPermissionParameters($userPermissions));

			//output
			if ($this->arParams['ONLY_COLUMNS'] === 'Y')
			{
				$this->arResult['ITEMS'] = [
					'items' => [],
					'config' => [],
					'columns' => array_values($this->getColumns()),
				];
			}
			else
			{
				$items = [];
				$columns = $this->getColumns();
				if (!empty($this->arParams['ADDITIONAL_FILTER']))
				{
					$filter = $this->arParams['ADDITIONAL_FILTER'];
					if (isset($filter['COLUMN']))
					{
						$filter[$this->statusKey] = $filter['COLUMN'];
						unset($filter['COLUMN']);
					}
					$items = $this->getItems($filter);
				}
				else
				{
					foreach ($columns as $column)
					{
						if ($column['dropzone'])
						{
							continue;
						}

						if (
							$column['count'] > 0
							|| ($this->isSkipColumnCountCheck() && $column['count'] !== -1)
						)
						{
							$filter = [];
							$filter[$this->statusKey] = $column['id'];
							$items += $this->getItems($filter);
						}

						if ($column['count'] < 0)
						{
							$column['count'] = 0;
						}
					}
				}
				$entity->appendMultiFieldData($items, $this->getKanban()->getAllowedFmTypes());
				$this->prepareItemsResult($items);

				$this->arResult['ITEMS'] = array(
					'columns' => array_values($columns),
					'items' => $items
				);

				//get activity
				if (!empty($this->arResult['ITEMS']['items']))
				{
					$itemIds = array_keys($this->arResult['ITEMS']['items']);

					if ($entity->isActivityCountersSupported())
					{
						$errors ??= [];
						$entityActivityCounter = new EntityActivityCounter(
							$this->getEntityTypeId(),
							$itemIds,
							$errors,
						);

						if ($this->arParams['API_VERSION'] > 1)
						{
							$entityActivityCounter->setSkipEmptyCounters();
						}

						$entityActivityCounter->appendToEntityItems($this->arResult['ITEMS']['items']);
						$this->arResult['ITEMS']['activityLimitIsExceeded'] = $entityActivityCounter->isLimitIsExceeded();
					}

					$entityBadges = new Kanban\EntityBadge($this->getEntityTypeId(), $itemIds);
					$entityBadges->appendToEntityItems($this->arResult['ITEMS']['items']);

					$this->arResult['ITEMS']['items'] = array_values($this->arResult['ITEMS']['items']);
				}

				$params = $this->getPreparedItemsConfigParams();
				$this->arResult['ITEMS']['config'] = $this->getKanban()->getItemsConfig($params);
			}

			if ($entity->isCategoriesSupported())
			{
				$canUpdateInCurrent = Container::getInstance()
					->getUserPermissions()
					->entityType()
					->canUpdateItemsInCategory(
						$entity->getTypeId(),
						$entity->getCategoryId(),
					)
				;
				$this->arResult['CATEGORIES'] = $canUpdateInCurrent
					? $entity->getCategoriesWithAddPermissions($userPermissions)
					: []
				;
				$this->ensureCurrentCategoryVisible($entity);
			}
		}

		$this->arResult['ITEMS']['last_id'] = $entity->getItemLastId();
		$this->arResult['ITEMS']['customFields'] = array_keys($this->arResult['MORE_FIELDS']);

		// items for demo import
		if (
			$this->arParams['IS_AJAX'] !== 'Y'
			&& isset($this->arResult['ITEMS']['columns'][0]['id'], $this->arResult['ITEMS']['columns'][0]['count'])
			&& ($this->blockPage * Kanban::BLOCK_SIZE >= $this->arResult['ITEMS']['columns'][0]['count'])
		)
		{
			if ($entity->isInlineEditorSupported() && $entity->isContactCenterSupported())
			{
				$this->arResult['ITEMS']['items'] = array_merge(
					$this->arResult['ITEMS']['items'],
					[[
						'id' => -1,
						'name' => null,
						'countable' => false,
						'droppable' => true,
						'draggable' => true,
						'columnId' => $this->arResult['ITEMS']['columns'][0]['id'],
						'special_type' => 'import',
					]]
				);
			}
			$this->arResult['ITEMS']['items'] = array_merge(
				$this->arResult['ITEMS']['items'],
				[[
					'id' => -2,
					'name' => null,
					'countable' => false,
					'droppable' => true,
					'draggable' => true,
					'columnId' => $this->arResult['ITEMS']['columns'][0]['id'],
					'special_type' => 'rest',
				]]
			);
		}

		$this->arResult['CONFIG_BY_VIEW_MODE'] = $this->getConfigByViewMode();

		PullManager::getInstance()->subscribeOnKanbanUpdate(
			$this->arParams['ENTITY_TYPE'],
			($this->arParams['EXTRA'] ?? null)
		);

		if ($this->arParams['IS_AJAX'] === 'Y')
		{
			return $this->arResult;
		}

		$this->arResult['RESTRICTED_FIELDS_ENGINE'] = $entity->getFieldsRestrictionsEngine();
		$this->arResult['RESTRICTED_FIELDS'] = $entity->getFieldsRestrictions();
		$this->arResult['SORT_SETTINGS'] = $entity->getSortSettings();
		$this->arResult['IS_LAST_ACTIVITY_ENABLED'] = $entity->isLastActivityEnabled();

		$GLOBALS['APPLICATION']->setTitle($entity->getTitle());

		return $this->IncludeComponentTemplate();
	}

	private function ensureCurrentCategoryVisible(\Bitrix\Crm\Kanban\Entity $entity): void
	{
		$currentCategoryId = $entity->getCategoryId();
		if ($currentCategoryId < 0 || isset($this->arResult['CATEGORIES'][$currentCategoryId]))
		{
			return;
		}

		$factory = Container::getInstance()->getFactory($entity->getTypeId());
		$category = $factory?->getCategory($currentCategoryId);
		if (!$category)
		{
			return;
		}

		$router = Container::getInstance()->getRouter();
		$this->arResult['CATEGORIES'][$currentCategoryId] = $category->getData();
		$this->arResult['CATEGORIES'][$currentCategoryId]['url'] =
			$router->getKanbanUrl($entity->getTypeId(), $currentCategoryId);

		$orderedCategories = [];
		foreach ($factory->getCategories() as $factoryCategory)
		{
			$factoryCategoryId = $factoryCategory->getId();
			if (isset($this->arResult['CATEGORIES'][$factoryCategoryId]))
			{
				$orderedCategories[$factoryCategoryId] = $this->arResult['CATEGORIES'][$factoryCategoryId];
			}
		}
		$this->arResult['CATEGORIES'] = $orderedCategories;
	}

	protected function getPreparedItemsConfigParams(): array
	{
		if ($this->arParams['API_VERSION'] <= 1)
		{
			return [];
		}

		$entity = $this->getEntity();
		$displayedFieldsList = $entity->getDisplayedFieldsList();

		$userTypeFields = [];
		foreach ($displayedFieldsList as $displayedFieldId => $displayedField)
		{
			$type = $displayedField->getType();
			if ($type === UserField::TYPE)
			{
				$userTypeFields[] = $displayedFieldId;
			}
		}

		$userIds = [];
		foreach ($this->arResult['ITEMS']['items'] as $item)
		{
			if (isset($item['lastActivity']['user']['id']))
			{
				$userIds[] = $item['lastActivity']['user']['id'];
			}

			foreach ($userTypeFields as $code)
			{
				$field = $this->getFieldByCode($item['fields'], $code);
				if (empty($field['value']))
				{
					continue;
				}

				if (is_array($field['value']))
				{
					$userIds = [...$userIds, ...$field['value']];
				}
				else
				{
					$userIds[] = $field['value'];
				}
			}
		}

		return [
			'userIds' => array_map('intval', array_unique($userIds)),
			'categoryId' => $entity->getCategoryId(),
			'fullConfig' => ($this->arParams['FULL_CONFIG'] === 'Y'),
		];
	}

	protected function getFieldByCode(array $fields, string $code): ?array
	{
		foreach ($fields as $field)
		{
			if (($field['code'] ?? null) === $code)
			{
				return $field;
			}
		}

		return null;
	}

	protected function prepareItemsResult(array &$items): void
	{
		$isOpenLinesInstalled = ModuleManager::isModuleInstalled('imopenlines');
		if ($isOpenLinesInstalled)
		{
			foreach ($items as &$item)
			{
				if (isset($item['im']) && is_array($item['im']))
				{
					$item['im'] = $this->getOpenLine($item['im']);
				}
			}
		}
	}

	protected function getOpenLine($openLines): string
	{
		$im = '';
		foreach ($openLines as $val)
		{
			if (isset($val['value']) && (str_starts_with($val['value'], 'imol|')))
			{
				$im = $val['value'];
				break;
			}

			if (empty($im) && is_array($val))
			{
				return $this->getOpenLine($val);
			}
		}

		return $im;
	}

	protected function getConfigByViewMode(): array
	{
		$viewMode = $this->arParams['VIEW_MODE'];

		if (ViewMode::isDatesBasedView($viewMode))
		{
			$canChangeColumns = 'false';
			return [
				'accessConfigPerms' => false,
				'canAddColumn' => $canChangeColumns,
				'canEditColumn'=> $canChangeColumns,
				'canRemoveColumn' => $canChangeColumns,
				'canSortColumn' => $canChangeColumns,
				'canChangeItemStage' => 'true',
				'columnsRevert' => 'true',
			];
		}

		$demoAccess = CJSCore::IsExtRegistered('intranet_notify_dialog') && ModuleManager::isModuleInstalled('im');
		$canChangeColumns = (($this->arResult['ACCESS_CONFIG_PERMS'] ?? null ) ? 'true' : 'false');

		return [
			'accessConfigPerms' => $this->arResult['ACCESS_CONFIG_PERMS'] ?? null,
			'canAddColumn' => ($demoAccess ? 'true' : $canChangeColumns),
			'canEditColumn'=> ($demoAccess ? 'true' : $canChangeColumns),
			'canRemoveColumn' => $canChangeColumns,
			'canSortColumn' => $canChangeColumns,
			'canChangeItemStage' => 'true',
			'columnsRevert' => 'false',
		];
	}

	/**
	 * The columns of the board are the stages of the funnel, so every column control follows the stage
	 * right and nothing else: the funnel the board belongs to stays navigation for whoever holds no
	 * right over its stages.
	 */
	protected function areStagesEditable(): bool
	{
		$entity = $this->getEntity();

		return $entity->getUserPermissions()->configuration()->canManageStages(
			ConfigurationCallSite::KanbanComponent,
			ConfigurationScope::ofEntityType($entity->getTypeId(), $entity->getCategoryId()),
		);
	}

	/**
	 * Get request-var for http-hit.
	 * @param string $var Request-var code.
	 * @return array|string|null
	 */
	protected function request(string $var): array | string | null
	{
		static $request = null;

		if ($request === null)
		{
			$context = Application::getInstance()->getContext();
			$request = $context->getRequest();
		}

		return $request->get($var);
	}

	private function getRequestStringValue(mixed $value): ?string
	{
		return is_array($value) ? null : ($value === null ? null : (string)$value);
	}

	/** @noinspection PhpUnused */
	protected function actionNotifyAdmin(): array
	{
		$userId = $this->request('userId');
		if ($userId && Loader::includeModule('im'))
		{
			$admins = $this->componentParams['ADMINS'];
			if (isset($admins[$userId]))
			{
				$pathColumnEdit = '/crm/configs/status/?ACTIVE_TAB=status_tab_' . $this->getEntity()->getStatusEntityId();
				$notifyMessageCallback = static fn (?string $languageId = null) =>
					Loc::getMessage(
						'CRM_ACCESS_NOTIFY_MESSAGE',
						[ '#URL#' => $pathColumnEdit ],
						$languageId,
					)
				;
				$notifySubject = static fn (?string $languageId = null) =>
					Loc::getMessage(
						'CRM_ACCESS_NOTIFY_MESSAGE_SUBJECT',
						[ '#URL#' => $pathColumnEdit ],
						$languageId,
					)
				;
				$notifyPlainText = static fn (?string $languageId = null) =>
					Loc::getMessage(
						'CRM_ACCESS_NOTIFY_MESSAGE_PLAIN_TEXT',
						null,
						$languageId,
					)
				;

				CIMNotify::Add([
					'TO_USER_ID' => $userId,
					'FROM_USER_ID' => $this->currentUserID,
					'NOTIFY_TYPE' => IM_NOTIFY_FROM,
					'NOTIFY_MODULE' => 'crm',
					'NOTIFY_EVENT' => 'admin_notification',
					'NOTIFY_TAG' => 'CRM|NOTIFY_ADMIN|' . $userId . '|' . $this->currentUserID,
					'NOTIFY_MESSAGE' => $notifyMessageCallback,
					'PARAMS' => [
						'COMPONENT_ID' => 'CrmEntity',
						'COMPONENT_PARAMS' => [
							'SUBJECT' => $notifySubject,
							'PLAIN_TEXT' => $notifyPlainText,
							'ENTITY' => [
								'ENTITY_TYPE' => mb_strtolower($this->getEntity()->getTypeName()),
							],
						],
					]
				]);
			}
		}

		return [
			'status' => 'success',
		];
	}

	/** @noinspection PhpUnused */
	protected function actionModifyStage(): array
	{
		if (!$this->areStagesEditable())
		{
			return [
				'ERROR' => Loc::getMessage('CRM_KANBAN_ERROR_ACCESS_DENIED'),
				'ERROR_CODE' => 'ACCESS_DENIED',
			];
		}

		$stages = $this->getColumns(
			true,
			true,
			[
				'originalColumns' => true,
			]
		);
		$delete = (bool)$this->request('delete');
		$columnId = (string)($this->getRequestStringValue($this->request('columnId')) ?? '');
		$columnName = $this->getRequestStringValue($this->request('columnName'));
		$columnColor = $this->getRequestStringValue($this->request('columnColor'));
		$columnDescription = $this->getRequestStringValue($this->request('columnDescription'));
		$afterColumnId = $this->getRequestStringValue($this->request('afterColumnId'));
		$eventId = $this->getRequestStringValue($this->request('eventId'));

		$entity = $this->getEntity();
		$isOrder = ($entity->getTypeName() === CCrmOwnerType::OrderName);
		$isExistingStage = $columnId !== '' && isset($stages[$columnId]);
		$isCreateOperation = !$isExistingStage;
		$explicitSemanticId = null;

		if ($this->getEntity() instanceof Dynamic && $isCreateOperation)
		{
			$explicitSemanticId = $this->resolveStageCreationSemanticId(
				$stages,
				$afterColumnId,
				$this->getRequestStringValue($this->request('beforeColumnId')),
			);
			if ($explicitSemanticId === null)
			{
				return ['ERROR' => Loc::getMessage('CRM_KANBAN_ERROR_INVALID_STAGE_SEMANTIC')];
			}

			$requestedSemanticId = $this->request('stageSemanticId');
			if ($requestedSemanticId !== null)
			{
				if (!is_string($requestedSemanticId))
				{
					return ['ERROR' => Loc::getMessage('CRM_KANBAN_ERROR_INVALID_STAGE_SEMANTIC')];
				}

				$requestedSemanticId = mb_strtoupper(trim($requestedSemanticId));
				if (
					!PhaseSemantics::isDefined($requestedSemanticId)
					|| !in_array($requestedSemanticId, PhaseSemantics::ALLOWED_SEMANTICS, true)
					|| !in_array(
						$requestedSemanticId,
						[PhaseSemantics::PROCESS, PhaseSemantics::FAILURE],
						true,
					)
					|| $requestedSemanticId !== $explicitSemanticId
				)
				{
					return ['ERROR' => Loc::getMessage('CRM_KANBAN_ERROR_INVALID_STAGE_SEMANTIC')];
				}
			}
		}

		$sort = 0;
		$semanticId = null;
		$isReordering = $isExistingStage && $this->isStageReorderingNeeded($columnId, $afterColumnId, $stages);
		$shouldNormalizeSort = !$isExistingStage || $isReordering;

		if ($shouldNormalizeSort && $afterColumnId !== null && (string)$afterColumnId === '0')
		{
			$sort = 0;
		}
		elseif ($shouldNormalizeSort && isset($stages[$afterColumnId]))
		{
			$sort = $stages[$afterColumnId]['real_sort'];
			if (($stages[$afterColumnId]['type'] ?? '') === 'LOOSE')
			{
				$semanticId = PhaseSemantics::FAILURE;
			}
		}
		$fields = [
			'ENTITY_ID' => $entity->getStatusEntityId(),
		];
		if ($shouldNormalizeSort)
		{
			$fields['SORT'] = ++$sort;
		}
		if ($semanticId)
		{
			$fields['SEMANTICS'] = $semanticId;
		}
		if ($explicitSemanticId !== null)
		{
			$fields['SEMANTICS'] = $explicitSemanticId;
			if ($explicitSemanticId === PhaseSemantics::PROCESS)
			{
				foreach ($stages as $stage)
				{
					if (($stage['type'] ?? '') === 'WIN')
					{
						$fields['SORT'] = min($fields['SORT'], (int)$stage['real_sort'] - 1);

						break;
					}
				}
			}
		}

		if ($columnName !== null)
		{
			$fields['NAME'] = $columnName;
			$fields['NAME_INIT'] = $columnName;
		}
		if ($columnColor)
		{
			$fields['COLOR'] = $columnColor;
		}
		if ($columnDescription !== null && !$isOrder)
		{
			$fields['DESCRIPTION'] = $columnDescription;
		}

		$fields['CATEGORY_ID'] = $this->getStageCategoryId(
			$entity->getCategoryId(),
			$fields['ENTITY_ID'],
		);
		$connection = null;
		if ($shouldNormalizeSort && !$delete)
		{
			$connection = Application::getConnection();
			$connection->startTransaction();
		}

		try
		{
			if ($isExistingStage)
			{
				if ($delete)
				{
					$result = $this->deleteStage($columnId, $stages, $eventId);
				}
				else
				{
					$internalId = $isOrder ? $columnId : $stages[$columnId]['real_id'];
					$updateFields = array_intersect_key(
						$fields,
						array_flip(['SORT', 'SEMANTICS', 'NAME', 'NAME_INIT', 'COLOR', 'DESCRIPTION']),
					);
					if (empty($updateFields))
					{
						return htmlspecialcharsback($stages[$columnId]);
					}

					$result = $this->updateStage(
						$internalId,
						$updateFields,
						[
							'STATUS_ID' => $columnId,
							'EVENT_ID' => $eventId,
						],
						!$shouldNormalizeSort,
					);
				}
			}
			else
			{
				$result = $explicitSemanticId === PhaseSemantics::FAILURE
					? $this->addFailureStage(
						$fields,
						isset($stages[$afterColumnId]) ? (int)$stages[$afterColumnId]['real_id'] : null,
					)
					: $this->addStage($fields, false);
				$internalId = $result->getId();
			}

			if ($result->isSuccess())
			{
				if ($delete)
				{
					return [
						'success' => true,
					];
				}
				if ($isOrder)
				{
					$statusId = $internalId;
				}
				else
				{
					$statusEntityId = $entity->getStatusEntityId();
					$status = new CCrmStatus($statusEntityId);
					$newStatus = $status->GetStatusById($internalId);
					$statusId = $newStatus['STATUS_ID'];
				}

				$stages = $this->getColumns(
					true,
					true,
					[
						'originalColumns' => true,
						'skipTotalSums' => true,
					]
				);

				if (isset($stages[$statusId]))
				{
					if ($shouldNormalizeSort)
					{
						$normalizeResult = $this->normalizeStageSorts($stages, $isOrder);
						if (!$normalizeResult->isSuccess())
						{
							$this->rollbackStageTransaction($connection);
							$normalizeErrors = $normalizeResult->getErrorMessages();

							return [
								'ERROR' => current($normalizeErrors),
							];
						}
					}

					$connection?->commitTransaction();
					$connection = null;
					if ($shouldNormalizeSort)
					{
						$this->sendStagePullEvent(
							$internalId,
							!$isExistingStage,
							$isOrder,
							(int)$stages[$statusId]['real_sort'],
							$eventId,
						);
					}

					return htmlspecialcharsback($stages[$statusId]);
				}

				$this->rollbackStageTransaction($connection);

				return [
					'ERROR' => 'Unknown error',
				];
			}

			$this->rollbackStageTransaction($connection);
		}
		catch (Throwable $exception)
		{
			$this->rollbackStageTransaction($connection);

			throw $exception;
		}

		$errors = $result->getErrorMessages();

		return [
			'ERROR' => current($errors),
		];
	}

	/**
	 * The new stage takes the semantics of one real neighbour in the full funnel: the left one,
	 * or the right one when the stage is inserted as the first column. The board filter is ignored.
	 */
	private function resolveStageCreationSemanticId(
		array $stages,
		?string $afterColumnId,
		?string $beforeColumnId,
	): ?string
	{
		$neighbourId = ($afterColumnId !== null && $afterColumnId !== '0') ? $afterColumnId : $beforeColumnId;
		$neighbourType = $neighbourId === null ? null : ($stages[$neighbourId]['type'] ?? null);

		return match ($neighbourType)
		{
			'PROGRESS' => PhaseSemantics::PROCESS,
			'LOOSE', 'WIN' => PhaseSemantics::FAILURE,
			default => null,
		};
	}

	private function addFailureStage(array $fields, ?int $afterStageId): AddResult
	{
		$result = new AddResult();
		$stages = \Bitrix\Crm\StatusTable::getList([
			'select' => ['ID', 'SORT', 'SEMANTICS'],
			'filter' => ['=ENTITY_ID' => $fields['ENTITY_ID']],
			'order' => ['SORT' => 'ASC', 'ID' => 'ASC'],
		])->fetchAll();
		$successSort = null;
		$afterStageSort = null;
		$failureStages = [];
		foreach ($stages as $stage)
		{
			if (($stage['SEMANTICS'] ?? '') === PhaseSemantics::SUCCESS)
			{
				$successSort = (int)$stage['SORT'];
			}
			elseif (($stage['SEMANTICS'] ?? '') === PhaseSemantics::FAILURE)
			{
				$failureStages[] = $stage;
				$stageSort = (int)$stage['SORT'];
				if ((int)$stage['ID'] === $afterStageId)
				{
					$afterStageSort = $stageSort;
				}
			}
		}

		if ($successSort === null)
		{
			$result->addError(new Error(Loc::getMessage('CRM_KANBAN_ERROR_STAGE_CREATION_CONTEXT')));

			return $result;
		}

		$candidateSort = ($afterStageSort ?? $successSort) + 1;
		$fields['SORT'] = $candidateSort;
		$fields['SEMANTICS'] = PhaseSemantics::FAILURE;
		usort(
			$failureStages,
			static fn(array $left, array $right): int => (int)$right['SORT'] <=> (int)$left['SORT'],
		);

		foreach ($failureStages as $failureStage)
		{
			if ((int)$failureStage['SORT'] < $candidateSort)
			{
				continue;
			}

			$updateResult = $this->updateStage(
				$failureStage['ID'],
				['SORT' => (int)$failureStage['SORT'] + 1],
			);
			if (!$updateResult->isSuccess())
			{
				$result->addErrors($updateResult->getErrors());

				return $result;
			}
		}

		return $this->addStage($fields, false);
	}

	private function getStageCategoryId(int $categoryId, string $statusEntityId): ?int
	{
		if ($categoryId > 0)
		{
			return $categoryId;
		}

		if (preg_match('/_STAGE_(\d+)$/', $statusEntityId, $matches) === 1)
		{
			return (int)$matches[1];
		}

		return null;
	}

	private function rollbackStageTransaction(?\Bitrix\Main\DB\Connection &$connection): void
	{
		if ($connection === null)
		{
			return;
		}

		$transactionConnection = $connection;
		$connection = null;
		try
		{
			$transactionConnection->rollbackTransaction();
		}
		catch (TransactionException $exception)
		{
			if ($exception->getMessage() !== 'Nested rollbacks are unsupported.')
			{
				throw $exception;
			}

			// Nested rollback is applied through a savepoint before the exception is raised.
		}
	}

	private function isStageReorderingNeeded(string $columnId, ?string $afterColumnId, array $stages): bool
	{
		if ($afterColumnId === null || $afterColumnId === $columnId)
		{
			return false;
		}

		$stageIds = array_keys($stages);
		$columnIndex = array_search($columnId, $stageIds, true);
		if ($columnIndex === false)
		{
			return false;
		}

		$previousColumnId = $columnIndex === 0 ? '0' : $stageIds[$columnIndex - 1];
		if ($afterColumnId === '0')
		{
			return $previousColumnId !== '0';
		}

		return isset($stages[$afterColumnId]) && $afterColumnId !== $previousColumnId;
	}

	protected function normalizeStageSorts(array $stages, bool $isOrder): Result
	{
		$result = new Result();
		$statusEntityId = $isOrder ? null : $this->getEntity()->getStatusEntityId();
		$requiresManualCacheInvalidation = $statusEntityId !== null
			&& $statusEntityId !== ''
			&& !isset(CCrmStatus::GetEntityTypes()[$statusEntityId])
		;
		$sort = 10;
		foreach ($stages as $stage)
		{
			if ((int)$stage['real_sort'] !== $sort)
			{
				$internalId = $isOrder ? $stage['id'] : $stage['real_id'];
				$updateResult = $this->updateStage(
					$internalId,
					['SORT' => $sort],
					invalidateStatusCache: false,
				);
				if (!$updateResult->isSuccess())
				{
					return $result->addErrors($updateResult->getErrors());
				}
			}

			$sort += 10;
		}

		if ($requiresManualCacheInvalidation)
		{
			\Bitrix\Crm\StatusTable::removeStatusesFromCache($statusEntityId);
		}

		return $result;
	}

	private function deleteStage(string $columnId, array $stages, ?string $eventId = null): Result
	{
		$result = new Result();

		if ($stages[$columnId]['type'] === 'WIN')
		{
			$result->addError(new Error(Loc::getMessage('CRM_KANBAN_ERROR_STAGE_IS_WIN')));

			return $result;
		}

		$entity = $this->getEntity();

		if (!$entity->isStageEmpty($columnId))
		{
			$result->addError(new Error(Loc::getMessage('CRM_KANBAN_ERROR_STAGE_IS_NOT_EMPTY')));

			return $result;
		}

		$statusEntityId = $entity->getStatusEntityId();
		if (empty($statusEntityId))
		{
			return $result;
		}

		//todo move to entity
		if ($entity->getTypeName() === CCrmOwnerType::OrderName)
		{
			$defaultStatuses = Order\OrderStatus::getDefaultStatuses();
			if (isset($defaultStatuses[$columnId]))
			{
				$result->addError(new Error(Loc::getMessage('CRM_KANBAN_ERROR_STAGE_IS_SYSTEM')));

				return $result;
			}

			if (!Loader::includeModule('sale'))
			{
				return $result;
			}

			$result = StatusTable::delete($columnId);
			if ($result->isSuccess())
			{
				$statusLanguageRaw = StatusLangTable::getList([
					'filter' => ['STATUS_ID' => $columnId],
					'select' => [
						'STATUS_ID',
						'LID',
					],
				]);

				while ($langPrimary = $statusLanguageRaw->fetch())
				{
					StatusLangTable::delete($langPrimary);
				}
			}
		}
		else
		{
			$internalId = $stages[$columnId]['real_id'];
			$statusObject = new CCrmStatus($statusEntityId);
			$statusInfo = $statusObject->GetStatusById($internalId);
			if ($statusInfo['SYSTEM'] === 'Y')
			{
				$result->addError(new Error(Loc::getMessage('CRM_KANBAN_ERROR_STAGE_IS_SYSTEM')));
				return $result;
			}

			$statusObject->delete($internalId);
			if (!empty($statusObject->GetLastError()))
			{
				$result->addError(new Error($statusObject->GetLastError()));
			}
			else
			{
				$item = Kanban\Entity::getInstance($entity->getTypeName())
					?->createPullStage($statusInfo)
				;

				$params = [
					'TYPE' => $entity->getTypeName(),
					'CATEGORY_ID' => (int)($this->arParams['EXTRA']['CATEGORY_ID'] ?? 0),
				];
				if ($eventId !== null)
				{
					$params['EVENT_ID'] = $eventId;
				}

				PullManager::getInstance()->sendStageDeletedEvent(
					$item,
					$params,
				);
			}
		}

		return $result;
	}

	protected function updateStage(
		int|string $id,
		array $fields = [],
		?array $params = [],
		bool $sendPull = true,
		bool $invalidateStatusCache = true,
	): Result
	{
		$result = new Result();

		$entity = $this->getEntity();
		//todo move to entity
		if ($entity->getTypeName() !== CCrmOwnerType::OrderName)
		{
			$statusEntityId = $entity->getStatusEntityId();
			$status = new CCrmStatus($statusEntityId);
			$status->update($id, $fields);
			if (!empty($status->GetLastError()))
			{
				$result->addError(new Error($status->GetLastError()));
			}
			else
			{
				if (
					$invalidateStatusCache
					&& !isset(CCrmStatus::GetEntityTypes()[$statusEntityId])
				)
				{
					\Bitrix\Crm\StatusTable::removeStatusesFromCache($statusEntityId);
				}

				if ($sendPull && isset($params['STATUS_ID']))
				{
					$this->sendStagePullEvent($id, false, false, null, $params['EVENT_ID'] ?? null);
				}
			}
		}
		else
		{
			if (!Loader::includeModule('sale'))
			{
				return $result;
			}

			$updateFields = array_intersect_key($fields, array_flip(['SORT', 'COLOR']));
			if (!empty($updateFields['COLOR']))
			{
				$updateFields['COLOR'] = "#".$updateFields['COLOR'];
			}

			if (!empty($updateFields))
			{
				$result = StatusTable::update($id, $updateFields);
			}

			if (!empty($fields['NAME']) && $result->isSuccess())
			{
				StatusLangTable::update(
					[
						'STATUS_ID' => $id,
						'LID' => $this->getLanguageId(),
					],
					['NAME' => $fields['NAME']]
				);
			}
		}

		return $result;
	}

	protected function addStage(array $fields = [], bool $sendPull = true): AddResult
	{
		$result = new AddResult();

		$entity = $this->getEntity();

		//todo move to entity
		if ($entity->getTypeName() !== CCrmOwnerType::OrderName)
		{
			$statusEntityId = $entity->getStatusEntityId();
			$status = new CCrmStatus($statusEntityId);
			$id = $status->add($fields);
			if (!empty($status->GetLastError()))
			{
				$result->addError(new Error($status->GetLastError()));
			}
			else
			{
				$result->setId($id);
			}
		}
		else
		{
			if (!Loader::includeModule('sale'))
			{
				return $result;
			}

			$createFields = array_intersect_key($fields, array_flip(['SORT', 'COLOR']));

			if (!empty($createFields['COLOR']) && !str_starts_with($createFields['COLOR'], '#'))
			{
				$createFields['COLOR'] = '#' . $createFields['COLOR'];
			}

			$orderStatusIds = [];
			$statusRaw = Order\OrderStatus::getList([
				'select' => ['ID'],
			]);
			while ($data = $statusRaw->fetch())
			{
				$orderStatusIds[$data['ID']] = $data;
			}

			do
			{
				$newId = chr(random_int(65, 90)); //A-Z
				if (is_array($result) && count($result) >= 27)
				{
					$newId .= chr(random_int(65, 90));
				}
			}
			while (isset($orderStatusIds[$newId]));
			$createFields['ID'] = $newId;
			$createFields['TYPE'] = Sale\OrderStatus::TYPE;
			$result = StatusTable::add($createFields);
			if ($result->isSuccess())
			{
				StatusLangTable::add([
					'STATUS_ID' => $result->getId(),
					'LID' => $this->getLanguageId(),
					'NAME' => $fields['NAME'],
				]);
			}
		}

		if ($sendPull && $result->isSuccess())
		{
			$pullFields = $fields;
			if ($entity->getTypeName() !== CCrmOwnerType::OrderName)
			{
				$pullFields = \Bitrix\Crm\StatusTable::getByPrimary((int)$result->getId())->fetch() ?: $fields;
			}
			else
			{
				$pullFields['STATUS_ID'] = $result->getId();
			}

			$item = Kanban\Entity::getInstance($entity->getTypeName())
				?->createPullStage($pullFields)
			;

			PullManager::getInstance()->sendStageAddedEvent(
				$item,
				[
					'TYPE' => $entity->getTypeName(),
					'CATEGORY_ID' => (int)($this->arParams['EXTRA']['CATEGORY_ID'] ?? 0),
				]
			);
		}

		return $result;
	}

	private function sendStagePullEvent(
		int|string $id,
		bool $isAdded,
		bool $isOrder,
		?int $sort = null,
		?string $eventId = null,
	): void
	{
		$entity = $this->getEntity();
		if ($isOrder)
		{
			$fields = StatusTable::getByPrimary($id)->fetch();
			if (!is_array($fields))
			{
				return;
			}

			$statusName = StatusLangTable::getByPrimary([
				'STATUS_ID' => $id,
				'LID' => $this->getLanguageId(),
			])->fetch();
			$fields['STATUS_ID'] = $fields['ID'];
			$fields['ENTITY_ID'] = $entity->getStatusEntityId();
			$fields['NAME'] = (string)($statusName['NAME'] ?? '');
			$fields['NAME_INIT'] = $fields['NAME'];
		}
		else
		{
			$fields = \Bitrix\Crm\StatusTable::getByPrimary((int)$id)->fetch();
			if (!is_array($fields))
			{
				return;
			}
		}
		if ($sort !== null)
		{
			$fields['SORT'] = $sort;
		}

		$item = Kanban\Entity::getInstance($entity->getTypeName())
			?->createPullStage($fields)
		;
		$params = [
			'TYPE' => $entity->getTypeName(),
			'CATEGORY_ID' => (int)($this->arParams['EXTRA']['CATEGORY_ID'] ?? 0),
		];
		if ($eventId !== null)
		{
			$params['EVENT_ID'] = $eventId;
		}

		if ($isAdded)
		{
			PullManager::getInstance()->sendStageAddedEvent($item, $params);
		}
		else
		{
			PullManager::getInstance()->sendStageUpdatedEvent($item, $params);
		}
	}

	protected function sendStageAddedEvent(array $fields): void
	{
		$entity = $this->getEntity();
		$item = Kanban\Entity::getInstance($entity->getTypeName())
			?->createPullStage($fields)
		;

		PullManager::getInstance()->sendStageAddedEvent(
			$item,
			[
				'TYPE' => $entity->getTypeName(),
				'CATEGORY_ID' => (int)($this->arParams['EXTRA']['CATEGORY_ID'] ?? 0),
			],
		);
	}

	/**
	 * Remove all private cards, for setting common.
	 * @return void
	 */
	protected function setCommonCardFields(): void
	{
		$this->getKanban()->removeUserAdditionalSelectFields();
	}

	protected function resetCardFields(): void
	{
		$this->arResult['MORE_FIELDS'] = $this->getKanban()->resetCardFields();
	}

	protected function actionSaveFields(): array
	{
		$type = (string) $this->request('type');
		$fields = $this->request('fields');

		$result = $this->getEntity()->saveAdditionalFields($fields, $type, $this->getKanban()->canEditSettings());

		$this->invalidateTotalSumsForCurrentUser();

		return $result;
	}

	protected function actionDelete(mixed $ids = null): array
	{
		$ids = ($ids ?: $this->request('id'));
		$ids = (array)$ids;

		if (empty($ids))
		{
			return [];
		}

		$ignore = ($this->request('ignore') === 'Y');

		$params = [
			'eventId' => $this->request->getPost('eventId'),
			'ANALYTICS' => $this->request->getPost('extra') && $this->request->getPost('extra')['ANALYTICS']
				? $this->request->getPost('extra')['ANALYTICS']
				: [],
		];

		try
		{
			$result = $this->getEntity()->deleteItemsV2($ids, $ignore, $params);
		}
		catch (Exception $exception)
		{
			return [
				'error' => $exception->getMessage(),
			];
		}

		$data = array_merge($result->getData(), ['errors' => []]);

		/** @var Error $error */
		foreach ($result->getErrorCollection() as $error)
		{
			$data['errors'][] = [
				'message' => $error->getMessage(),
				'data' => $error->getCustomData(),
			];
		}

		$this->invalidateTotalSumsForCurrentUser();

		return $data;
	}

	private function actionUpdateEntityStatus(int $id, $status, array $statuses = []): Result
	{
		$request = Application::getInstance()->getContext()->getRequest();

		$result = new Result();
		$entity = $this->getEntity();

		if (!$entity->checkUpdatePermissions($id))
		{
			return $result->addError(new Error(Loc::getMessage('CRM_KANBAN_ERROR_ACCESS_DENIED')));
		}

		$item = $entity->getItem($id, [$entity->getDbStageFieldName()]);
		if (!$item)
		{
			return $result->addError(new Error(Loc::getMessage('CRM_KANBAN_ERROR_ACCESS_DENIED')));
		}

		if ($entity->getSortSettings()->isUserSortSupported())
		{
			Kanban\SortTable::setPrevious([
				'ENTITY_TYPE_ID' => $entity->getTypeName(),
				'ENTITY_ID' => $id,
				'PREV_ENTITY_ID' => $request->get('prev_entity_id')
			]);

			// remember last id
			$this->getKanban()->rememberLastId($entity->getItemLastId());
		}

		$statusKey = $this->statusKey;
		$isStatusChanged = (($item[$statusKey] ?? null) !== $status);
		if (!$isStatusChanged)
		{
			return $result;
		}

		$ajaxParamsName = ((int) $request->getPost('version') === 2) ? 'ajaxParams' : 'status_params';
		$newStateParams = (array)$request->getPost($ajaxParamsName);

		$eventId = $request->getPost('eventId');
		if ($eventId)
		{
			$newStateParams['eventId'] = $eventId;
		}

		$result = $entity->updateItemStage(
			$id,
			$status,
			$newStateParams,
			$statuses
		);

		if (!$result->isSuccess())
		{
			return $result;
		}

		// order synchronization
		if ($entity->getTypeId() === CCrmOwnerType::Deal)
		{
			(new OrderDealSynchronizer())->updateOrderFromDeal($id);
		}

		$this->needPrepareColumns = true;

		$this->invalidateTotalSumsForCurrentUser();

		return $result;
	}

	/** @noinspection PhpUnused */
	protected function actionRefreshAccount(): array
	{
		$ids = $this->request('id');
		$ids = (array)$ids;

		$entity = $this->getEntity();
		if (empty($ids) || $entity->getTypeName() !== CCrmOwnerType::DealName)
		{
			return [];
		}

		$idForUpdate = [];
		foreach ($ids as $id)
		{
			if ($entity->checkUpdatePermissions($id))
			{
				$idForUpdate[] = $id;
			}
		}

		if (!empty($idForUpdate))
		{
			CCrmDeal::refreshAccountingData($idForUpdate);
		}

		// Intentionally no cache invalidation here: this action handles pull events
		// about changes by other users; staleness is bounded by TotalSumsCache TTL.
		return [];
	}

	/** @noinspection PhpUnused */
	protected function actionSetAssigned(): array
	{
		$ids = (array)$this->request('ids');
		$assignedId = (int)$this->request('assignedId');
		if ($assignedId <= 0 || empty($ids))
		{
			return [];
		}

		$this->getEntity()->setItemsAssigned($ids, $assignedId);

		$this->invalidateTotalSumsForCurrentUser();

		return [];
	}

	/** @noinspection PhpUnused */
	protected function actionOpen(): array
	{
		$ids = (array)$this->request('id');
		if (empty($ids))
		{
			return [];
		}

		$isOpened = ($this->request('flag') === 'Y');

		$this->getEntity()->updateItemsOpened($ids, $isOpened);

		$this->invalidateTotalSumsForCurrentUser();

		return [];
	}

	/** @noinspection PhpUnused */
	protected function actionChangeCategory(): array
	{
		$ids = (array)$this->request('id');
		if (empty($ids))
		{
			return [];
		}

		$categoryId = (int)$this->request('category');
		$result = $this->getEntity()->updateItemsCategory($ids, $categoryId);

		if (!$result->isSuccess())
		{
			$errorMessages = $result->getErrorMessages();

			return [
				'ERROR' => reset($errorMessages),
			];
		}

		$this->invalidateTotalSumsForCurrentUser();

		return [];
	}

	/**
	 * @noinspection PhpUnused
	 * Show or hide contact center block in option.
	 * @return array
	 */
	protected function actionToggleCC(): array
	{
		$hidden = CUserOptions::getOption(
			static::OPTION_CATEGORY,
			static::OPTION_NAME_HIDE_CONTACT_CENTER,
		);
		CUserOptions::setOption(
			static::OPTION_CATEGORY,
			static::OPTION_NAME_HIDE_CONTACT_CENTER,
			!$hidden
		);
		return [];
	}

	/**
	 * @noinspection PhpUnused
	 * Show or hide REST demo block in option.
	 * @return array
	 */
	protected function actionToggleRest(): array
	{
		$hidden = CUserOptions::getOption(
			static::OPTION_CATEGORY,
			static::OPTION_NAME_HIDE_REST_DEMO,
		);
		CUserOptions::setOption(
			static::OPTION_CATEGORY,
			static::OPTION_NAME_HIDE_REST_DEMO,
			!$hidden
		);
		return [];
	}

	/** @noinspection PhpUnused */
	protected function actionSetCurrentSortType($sortType = null): array
	{
		$sortType = $sortType ?: $this->request('sortType');

		$result = $this->getEntity()->setCurrentSortType((string)$sortType);
		if (!$result->isSuccess())
		{
			$errorMessages = $result->getErrorMessages();

			return [
				'ERROR' => reset($errorMessages),
			];
		}

		return [];
	}

	/**
	 * @return Kanban\Entity
	 * @throws Kanban\EntityNotFoundException
	 */
	protected function getEntity(): Kanban\Entity
	{
		return $this->getKanban()->getEntity();
	}

	/**
	 * @return Kanban
	 */
	protected function getKanban(): Kanban
	{
		return $this->kanban;
	}

	protected function prepareHeaderSections(): void
	{
		foreach($this->arParams['HEADERS_SECTIONS'] as $section)
		{
			$this->arResult['HEADERS_SECTIONS'][$section['id']] = $section;
			if (!empty($section['default']))
			{
				$this->arResult['DEFAULT_HEADER_SECTION_ID'] = $section['id'];
			}
		}
	}

	private function invalidateTotalSumsForCurrentUser(): void
	{
		$userId = (int)\Bitrix\Crm\Service\Container::getInstance()->getContext()->getUserId();
		if ($userId <= 0)
		{
			return;
		}
		$cache = \Bitrix\Main\DI\ServiceLocator::getInstance()->get(\Bitrix\Crm\Kanban\TotalSumsCache::class);
		if (!$cache->isEnabled())
		{
			return;
		}
		// Invalidate the current user's cache across ALL categories of this entity
		// type: a write action may affect sums in a category other than the one
		// currently open. Cheap (per-user) and removes any cross-category staleness.
		$cache->cleanDir($this->getEntityTypeId(), $userId);
	}

	private function getStub(): array
	{
		$entityTypeId = $this->getEntityTypeId();
		if (!CCrmOwnerType::isPossibleDynamicTypeId($entityTypeId))
		{
			return [];
		}

		if (IntranetManager::isEntityTypeInCustomSection($entityTypeId))
		{
			return [
				'title' => Loc::getMessage('CRM_KANBAN_AUTOMATED_SOLUTION_STUB_TITLE'),
				'description' => Loc::getMessage('CRM_KANBAN_AUTOMATED_SOLUTION_STUB_DESCRIPTION'),
			];
		}

		return [];
	}

	private function showErrorCounterByActivityResponsible(): bool
	{
		return CounterSettings::getInstance()->useActivityResponsible();
	}

	private function getStageAnalyticsLabels(): array
	{
		$subSectionKanban = Dictionary::SUB_SECTION_KANBAN;

		$createStage = (new FunnelAnalytics\Stage\CreateEvent(subSection: $subSectionKanban))->buildData();
		$renameStage = (new FunnelAnalytics\Stage\RenameEvent(subSection: $subSectionKanban))->buildData();
		$deleteStage = (new FunnelAnalytics\Stage\DeleteEvent(subSection: $subSectionKanban))->buildData();
		$updateStage = (new FunnelAnalytics\Stage\UpdateEvent(subSection: $subSectionKanban))->buildData();

		return [
			'createStageLabel' => $createStage,
			'renameStageLabel' => $renameStage,
			'deleteStageLabel' => $deleteStage,
			'updateStageLabel' => $updateStage,
		];
	}
}
