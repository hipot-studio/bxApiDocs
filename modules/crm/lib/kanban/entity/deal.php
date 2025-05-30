<?php

namespace Bitrix\Crm\Kanban\Entity;

use Bitrix\Crm\Category\DealCategory;
use Bitrix\Crm\Category\DealCategoryChangeError;
use Bitrix\Crm\Component\EntityList\ClientDataProvider;
use Bitrix\Crm\Component\EntityList\FieldRestrictionManager;
use Bitrix\Crm\Component\EntityList\FieldRestrictionManagerTypes;
use Bitrix\Crm\Deal\PaymentsRepository;
use Bitrix\Crm\Deal\ShipmentsRepository;
use Bitrix\Crm\Filter;
use Bitrix\Crm\Item;
use Bitrix\Crm\Kanban\Entity;
use Bitrix\Crm\PhaseSemantics;
use Bitrix\Crm\Recurring;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\Display\Field;
use Bitrix\Crm\Settings\DealSettings;
use Bitrix\Main\Error;
use Bitrix\Main\Filter\EntitySettings;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Main\UI\Filter\Options;

class Deal extends Entity
{
	private FieldRestrictionManager $dealFieldRestrictionManager;

	public function __construct()
	{
		parent::__construct();

		$this->dealFieldRestrictionManager = new FieldRestrictionManager(
			FieldRestrictionManager::MODE_KANBAN,
			[FieldRestrictionManagerTypes::CLIENT, FieldRestrictionManagerTypes::OBSERVERS],
			\CCrmOwnerType::Deal
		);
	}

	public function getTypeName(): string
	{
		return \CCrmOwnerType::DealName;
	}

	public function getItemsSelectPreset(): array
	{
		return [
			'ID',
			'STAGE_ID',
			'TITLE',
			'DATE_CREATE',
			'BEGINDATE',
			'OPPORTUNITY',
			'OPPORTUNITY_ACCOUNT',
			'EXCH_RATE',
			'CURRENCY_ID',
			'ACCOUNT_CURRENCY_ID',
			'IS_REPEATED_APPROACH',
			'IS_RETURN_CUSTOMER',
			'CONTACT_ID',
			'COMPANY_ID',
			'MODIFY_BY_ID',
			'ASSIGNED_BY',
			Item::FIELD_NAME_LAST_ACTIVITY_TIME,
			Item::FIELD_NAME_LAST_ACTIVITY_BY,
		];
	}

	public function isContactCenterSupported(): bool
	{
		return true;
	}

	public function getTypeInfo(): array
	{
		return array_merge(
			parent::getTypeInfo(),
			[
				'canUseIgnoreItemInPanel' => true,
				'hasPlusButtonTitle' => true,
				'showPersonalSetStatusNotCompletedText' => true,
				'isRecyclebinEnabled' => DealSettings::getCurrent()->isRecycleBinEnabled(),
				'canUseCreateTaskInPanel' => true,
				'canUseCallListInPanel' => true,
				'canUseMergeInPanel' => true,
			]
		);
	}

	public function getFilterOptions(): Options
	{
		$options = parent::getFilterOptions();

		$this->dealFieldRestrictionManager->removeRestrictedFields($options);

		return $options;
	}

	public function getFieldsRestrictionsEngine(): string
	{
		$parentFieldsRestrictions = parent::getFieldsRestrictionsEngine();
		$dealFieldsRestrictions = $this->dealFieldRestrictionManager->fetchRestrictedFieldsEngine(
			$this->getGridId(),
			[],
			$this->getFilter()
		);

		return implode("\n", [$parentFieldsRestrictions, $dealFieldsRestrictions]);
	}

	public function getFieldsRestrictions(): array
	{
		$parentFieldsRestrictions = parent::getFieldsRestrictions();

		$dealFieldsRestrictions = $this->dealFieldRestrictionManager->getFilterFields(
			$this->getGridId(),
			[],
			$this->getFilter()
		);

		return [...$parentFieldsRestrictions, ...$dealFieldsRestrictions];
	}

	protected function getFilter(): Filter\Filter
	{
		if(!$this->filter)
		{
			$flags = EntitySettings::FLAG_NONE | Filter\DealSettings::FLAG_ENABLE_CLIENT_FIELDS;
			$userPermissions = Container::getInstance()->getUserPermissions()->getCrmPermissions();

			$this->filter = Filter\Factory::createEntityFilter(
				new Filter\DealSettings([
					'ID' => $this->getGridId(),
					'categoryID' => $this->getCategoryId(),
					'flags' => $flags,
					'categoryAccess' => [
						'READ' => \CCrmDeal::getPermittedToReadCategoryIDs($userPermissions),
					],
				])
			);
		}

		return $this->filter;
	}

	public function getFilterPresets(): array
	{
		return (new Filter\Preset\Deal())
			->setDefaultValues($this->getFilter()->getDefaultFieldIDs())
			->setCategoryId($this->categoryId)
			->getDefaultPresets()
		;
	}

	public function isRestPlacementSupported(): bool
	{
		return true;
	}

	public function isActivityCountersFilterSupported(): bool
	{
		return $this->factory->isCountersEnabled();
	}

	public function isRecurringSupported(): bool
	{
		return true;
	}

	public function isExclusionSupported(): bool
	{
		return true;
	}

	public function isNeedToRunAutomation(): bool
	{
		return true;
	}

	protected function getDefaultAdditionalSelectFields(): array
	{
		return [
			'TITLE' => '',
			'OPPORTUNITY' => '',
			'DATE_CREATE' => '',
			'PAYMENT_STAGE' => Loc::getMessage('CRM_KANBAN_FIELD_PAYMENT_STAGE'),
			'DELIVERY_STAGE' => Loc::getMessage('CRM_KANBAN_FIELD_DELIVERY_STAGE'),
			'CLIENT' => '',
			'PROBLEM_NOTIFICATION' => '',
			'OBSERVER' => Loc::getMessage('CRM_KANBAN_FIELD_OBSERVER'),
		];
	}

	public function getStageFieldName(): string
	{
		return 'STAGE_ID';
	}

	protected function getDetailComponentName(): ?string
	{
		return 'bitrix:crm.deal.details';
	}

	public function getPermissionParameters(): array
	{
		$result = parent::getPermissionParameters();
		$result['ACCESS_IMPORT'] = $this->userPermissions->entityType()->canImportItems(\CCrmOwnerType::Deal);

		return $result;
	}

	protected function hasStageDependantRequiredFields(): bool
	{
		return true;
	}

	public function getTableAlias(): string
	{
		return \CCrmDeal::TABLE_ALIAS;
	}

	public function prepareItemCommonFields(array $item): array
	{
		$item['PRICE'] = $item['OPPORTUNITY'] ?? null;
		$item['DATE'] = $item['DATE_CREATE'] ?? null;
		$item['OBSERVER'] = $item['OBSERVER'] ?? null;

		return parent::prepareItemCommonFields($item);
	}

	public function appendRelatedEntitiesValues(array $items, array $selectedFields): array
	{
		$items = parent::appendRelatedEntitiesValues($items, $selectedFields);
		$dealIds = array_keys($items);

		if (in_array('DELIVERY_STAGE', $selectedFields, true))
		{
			$shipmentStages = (new ShipmentsRepository())->getShipmentStages($dealIds);
			foreach ($items as $itemId => $item)
			{
				$items[$itemId]['DELIVERY_STAGE'] = $shipmentStages[$itemId] ?? null;
			}
		}

		if (in_array('PAYMENT_STAGE', $selectedFields, true))
		{
			$paymentStages = (new PaymentsRepository())->getPaymentStages($dealIds);
			foreach ($items as $itemId => $item)
			{
				if (isset($paymentStages[$itemId]))
				{
					$items[$itemId]['PAYMENT_STAGE'] = $paymentStages[$itemId];
				}
			}
		}

		return $items;
	}

	protected function getExtraDisplayedFields()
	{
		$result = parent::getExtraDisplayedFields();

		$result['DELIVERY_STAGE'] = Field::createByType(Field\DeliveryStatusField::TYPE, 'DELIVERY_STAGE');
		$result['PAYMENT_STAGE'] = Field::createByType(Field\PaymentStatusField::TYPE,'PAYMENT_STAGE');

		return $result;
	}

	public function updateItemsCategory(array $ids, int $categoryId): Result
	{
		$result = new Result();

		foreach($ids as $id)
		{
			if (!(
				$id > 0
				&& $this->userPermissions->item()->canUpdate(\CCrmOwnerType::Deal, $id)
				&& $this->userPermissions->entityType()->canAddItemsInCategory(\CCrmOwnerType::Deal, $categoryId)
			))
			{
				$result->addError(new Error(Loc::getMessage('CRM_COMMON_ERROR_ACCESS_DENIED')));
				continue;
			}
			$recurringData = Recurring\Manager::getList(
				[
					'filter' => ['DEAL_ID' => $id],
					'limit' => 1
				],
				Recurring\Manager::DEAL
			);
			$options = null;
			if ($recurringData->fetch())
			{
				$options = ['REGISTER_STATISTICS' => false];
			}
			$error = \CCrmDeal::moveToCategory($id, $categoryId, $options);
			if ($error === DealCategoryChangeError::NONE)
			{
				$dbResult = \CCrmDeal::GetListEx(
					array(),
					array('=ID' => $id, 'CHECK_PERMISSIONS' => 'N'),
					false,
					false,
					['STAGE_ID', 'CATEGORY_ID']
				);
				$newFields = $dbResult->Fetch();
				$this->runAutomationOnUpdate($id, $newFields);
			}
		}

		return $result;
	}

	public function updateItemStage(int $id, string $stageId, array $newStateParams, array $stages): Result
	{
		$result = $this->getItemViaLoadedItems($id);
		if (!$result->isSuccess())
		{
			return $result;
		}

		$item = $result->getData()['item'];

		$stageCategoryID = (int) DealCategory::resolveFromStageID($stageId);
		$dealCategoryID = (int) $item['CATEGORY_ID'];
		if($dealCategoryID !== $stageCategoryID)
		{
			return $result->addError(new Error(Loc::getMessage('CRM_KANBAN_ERROR_DEAL_STAGE_MISMATCH')));
		}

		return parent::updateItemStage($id, $stageId, $newStateParams, $stages);
	}

	public function getFilterLazyLoadParams(): ?array
	{
		$path = '/bitrix/components/bitrix/crm.deal.list/filter.ajax.php'
			. '?filter_id='.urlencode($this->getGridId()) . '&category_id=' . $this->getCategoryId() . '&is_recurring=N&siteID=' . SITE_ID . '&' . bitrix_sessid_get();

		return [
			'GET_LIST' => $path . '&action=list',
			'GET_FIELD' => $path . '&action=field',
			'GET_FIELDS' => $path . '&action=fields',
		];
	}

	public function getGridFilter(?string $filterId = null): array
	{
		$result = parent::getGridFilter($filterId);

		$filterOptions = $this->getFilterOptions();
		if ($filterId)
		{
			$filterOptions->setCurrentFilterPresetId($filterId);
		}

		$filterFieldsValues = $filterOptions->GetFilter($result);
		$this->getContactDataProvider()->prepareFilter($result, $filterFieldsValues);
		$this->getCompanyDataProvider()->prepareFilter($result, $filterFieldsValues);

		return $result;
	}

	public function getItems(array $parameters): \CDBResult
	{
		if (isset($parameters['select']))
		{
			$this->getContactDataProvider()->prepareSelect($parameters['select']);
			$this->getCompanyDataProvider()->prepareSelect($parameters['select']);
		}

		return parent::getItems($parameters);
	}

	public function getPopupFields(string $viewType): array
	{
		$fields = parent::getPopupFields($viewType);
		foreach ($fields as $i => $field)
		{
			if (
				mb_strpos($field['NAME'], 'CONTACT_') === 0
				|| mb_strpos($field['NAME'], 'COMPANY_') === 0
				|| mb_strpos($field['NAME'], 'ACTIVITY_FASTSEARCH_') === 0
			)
			{
				unset($fields[$i]);
			}

			if (
				$viewType === static::VIEW_TYPE_EDIT
				&& in_array(
					$field['NAME'],
					[
						'ORDER_STAGE',
						'DELIVERY_STAGE',
						'PAYMENT_STAGE',
						'PAYMENT_PAID',
						'ORDER_SOURCE',
						'IS_PRODUCT_RESERVED',
						'ROBOT_DEBUGGER',
					]
				)
			)
			{
				unset($fields[$i]);
			}
		}

		if ($viewType !== static::VIEW_TYPE_EDIT)
		{
			if (ClientDataProvider::getPriorityEntityTypeId() === \CCrmOwnerType::Contact)
			{
				$firstProvider = $this->getContactDataProvider();
				$secondProvider = $this->getCompanyDataProvider();
			}
			else
			{
				$firstProvider = $this->getCompanyDataProvider();
				$secondProvider = $this->getContactDataProvider();
			}
			$fields = array_merge(
				$fields,
				$firstProvider->getPopupFields(),
				$secondProvider->getPopupFields(),
			);
		}

		return $fields;
	}

	/**
	 * @internal
	 */
	public function prepareFieldsSections(array $configuration): array
	{
		$sections = parent::prepareFieldsSections($configuration);

		$contactSection = [
			'name' => 'contact_fields',
			'title' => Loc::getMessage('CRM_KANBAN_FIELD_SECTION_CONTACTS'),
			'type' => 'section',
			'elementsRule' => '/^CONTACT\_/',
			'viewTypes' => ['view'],
		];
		$companySection = [
			'name' => 'company_fields',
			'title' => Loc::getMessage('CRM_KANBAN_FIELD_SECTION_COMPANIES'),
			'type' => 'section',
			'elementsRule' => '/^COMPANY\_/',
			'viewTypes' => ['view'],
		];
		if (ClientDataProvider::getPriorityEntityTypeId() === \CCrmOwnerType::Contact)
		{
			$sections[] = $contactSection;
			$sections[] = $companySection;
		}
		else
		{
			$sections[] = $companySection;
			$sections[] = $contactSection;
		}

		return $sections;
	}

	/**
	 * @return array
	 */
	public function getSemanticIds(): array
	{
		return [
			PhaseSemantics::PROCESS,
			PhaseSemantics::SUCCESS,
			PhaseSemantics::FAILURE,
		];
	}

	final protected function isItemsAssignedNotificationSupported(): bool
	{
		return true;
	}
}
