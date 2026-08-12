<?php

namespace Bitrix\CrmMobile\Kanban\ControllerStrategy;

use Bitrix\Crm\Category\Entity\Category;
use Bitrix\Crm\Component\EntityList\FieldRestrictionManager;
use Bitrix\Crm\Component\EntityList\FieldRestrictionManagerTypes;
use Bitrix\Crm\Counter\EntityCounterFactory;
use Bitrix\Crm\Counter\EntityCounterType;
use Bitrix\Crm\Field\Collection;
use Bitrix\Crm\Filter\ClientDataProvider;
use Bitrix\Crm\Filter\ClientUserFieldDataProvider;
use Bitrix\Crm\Filter\FactoryOptionable;
use Bitrix\Crm\Filter\Preset\Company;
use Bitrix\Crm\Filter\Preset\Contact;
use Bitrix\Crm\Item;
use Bitrix\Crm\Kanban\Exception;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\Display;
use Bitrix\Crm\Service\Display\Field;
use Bitrix\Crm\Service\Factory;
use Bitrix\CrmMobile\Kanban\Client;
use Bitrix\CrmMobile\Kanban\Entity\ControllerStrategy\FieldNotFoundException;
use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\Filter\DataProvider;
use Bitrix\Main\Result;
use Bitrix\Main\UI\Filter\Options;
use Bitrix\Main\UI\PageNavigation;
use Bitrix\Crm\Kanban\Entity;

final class ListStrategy extends Base
{
	protected const LIMIT = 20;
	protected const DEFAULT_SHOW_FIELD_NAMES = ['CREATED_TIME', 'ASSIGNED_BY_ID'];

	protected ?Options $filterOptions = null;
	protected array $showedFieldsList = [];
	protected ?Collection $fieldsCollection = null;
	protected ?array $fieldsMap = null;
	protected ?\Bitrix\Main\Grid\Options $gridOptions = null;
	protected array $factories = [];
	protected ?DataProvider $provider = null;
	protected ?DataProvider $ufProvider = null;
	/** @var DataProvider[]|null */
	protected ?array $clientFilterProviders = null;
	/** @var Client\ListDataProvider[]|null */
	protected ?array $clientProviders = null;
	protected ?FieldRestrictionManager $fieldRestrictionManager = null;

	public function updateItemStage(int $id, int $stageId): Result
	{
		throw new Exception('UpdateItemStage not implemented');
	}

	public function deleteItem(int $id, array $params = []): Result
	{
		$factory = Container::getInstance()->getFactory($this->getEntityTypeId());
		$item = $factory->getItem($id);
		if (!$item)
		{
			return new Result();
		}

		$context = clone Container::getInstance()->getContext();
		if (isset($params['eventId']))
		{
			$context->setEventId($params['eventId']);
		}

		return $factory->getDeleteOperation($item, $context)->launch();
	}

	public function changeCategory(array $ids, int $categoryId): Result
	{
		$factory = Container::getInstance()->getFactory($this->getEntityTypeId());

		if ($factory && $factory->isCategoriesEnabled())
		{
			return parent::changeCategory($ids, $categoryId);
		}

		throw new Exception('Entity categories not supported or disabled');
	}

	public function getList(?PageNavigation $pageNavigation): array
	{
		$filter = $this->getPreparedFilter();
		$parameters = $this->buildParameters($filter, $pageNavigation);

		return $this->getPreparedItems($parameters);
	}

	public function getItemParams(array $items): array
	{
		$fieldsCollection = $this->getFieldsCollection();
		$fieldsMap = $this->getFieldsMap();

		$displayedFields = $this->getDisplayedFields($fieldsCollection, $fieldsMap);

		$allDisplayValues =
			(new Display($this->getEntityTypeId(), $displayedFields))
				->setItems($items)
				->getAllValues()
		;

		$entityAttributes = $this->getEntityAttributes($items, 'ID');

		return [
			'displayValues' => $allDisplayValues,
			'fieldsCollection' => $fieldsCollection,
			'permissionEntityAttributes' => $entityAttributes,
		];
	}

	protected function getProvider(): DataProvider
	{
		if (!$this->provider)
		{
			$filterFactory = Container::getInstance()->getFilterFactory();
			$settings = $filterFactory->getSettings($this->getEntityTypeId(), $this->getGridId());

			$this->provider = $filterFactory->getDataProvider($settings);
			if ($this->provider instanceof FactoryOptionable)
			{
				$this->provider->setForceUseFactory(true);
			}
		}

		return $this->provider;
	}

	protected function getUfProvider(): DataProvider
	{
		if (!$this->ufProvider)
		{
			$filterFactory = Container::getInstance()->getFilterFactory();
			$settings = $filterFactory->getSettings($this->getEntityTypeId(), $this->getGridId());

			$this->ufProvider = $filterFactory->getUserFieldDataProvider($settings);
		}

		return $this->ufProvider;
	}

	/**
	 * @return DataProvider[]
	 */
	protected function getClientFilterProviders(): array
	{
		if ($this->clientFilterProviders === null)
		{
			$filterFactory = Container::getInstance()->getFilterFactory();
			$settings = $filterFactory->getSettings($this->getEntityTypeId(), $this->getGridId());
			$this->clientFilterProviders = $filterFactory->getSupportedClientDataProviders($settings);
		}

		return $this->clientFilterProviders;
	}

	/**
	 * @return Client\ListDataProvider[]
	 */
	protected function getClientProviders(): array
	{
		if ($this->clientProviders === null)
		{
			$clientProviders = [];
			$factory = $this->getFactory($this->getEntityTypeId());
			if ($factory->isClientContactEnabled())
			{
				$clientProviders[] = $this->createClientProvider(\CCrmOwnerType::Contact);
			}
			if ($factory->isClientCompanyEnabled())
			{
				$clientProviders[] = $this->createClientProvider(\CCrmOwnerType::Company);
			}

			$this->clientProviders = $clientProviders;
		}

		return $this->clientProviders;
	}

	protected function createClientProvider(int $clientEntityTypeId): Client\ListDataProvider
	{
		$dataProvider = new Client\ListDataProvider($clientEntityTypeId);
		$dataProvider->setGridId($this->getGridId());

		return $dataProvider;
	}

	final protected function getFieldRestrictionManager(): FieldRestrictionManager
	{
		if ($this->fieldRestrictionManager === null)
		{
			$restrictionTypes = [
				FieldRestrictionManagerTypes::OBSERVERS,
			];
			if ($this->getFactory($this->getEntityTypeId())->isClientFieldsEnabled())
			{
				$restrictionTypes[] = FieldRestrictionManagerTypes::CLIENT;
			}

			$this->fieldRestrictionManager = new FieldRestrictionManager(
				FieldRestrictionManager::MODE_KANBAN,
				$restrictionTypes,
				$this->getEntityTypeId(),
			);
		}

		return $this->fieldRestrictionManager;
	}

	protected function getPreparedFilter(): array
	{
		$filter = [];

		if (!empty($this->params['search']))
		{
			$filter['FIND'] = $this->params['search'];
		}

		$filterParams = $this->getFilterParams();

		if (isset($filterParams['CATEGORY_ID']))
		{
			$filter['CATEGORY_ID'] = (int)$filterParams['CATEGORY_ID'];
		}

		$presetId = ($filterParams['FILTER_PRESET_ID'] ?? null);
		if ($presetId)
		{
			$filterOptions = $this->getFilterOptions();
			$this->setFilterPreset($presetId, $filterOptions);

			$params = $filterOptions->setCurrentFilterPresetId($presetId)->getFilter();
			$filter = array_merge($params, $filter);
		}

		return $filter;
	}

	protected function setFilterPreset(string $presetId, Options $filterOptions): void
	{
		// "filter_rows" do not contain restricted columns, but "fields" still contain
		// we need to remove restricted columns from "fields" to remove them from actual search conditions
		$filterSettings = $filterOptions->getFilterSettings($presetId);
		$isFilterSettingsCheckable =
			isset($filterSettings['fields'], $filterSettings['filter_rows'])
			&& is_array($filterSettings['fields'])
			&& is_string($filterSettings['filter_rows'])
		;
		if ($isFilterSettingsCheckable)
		{
			$fields = $filterSettings['fields'];
			$filterRows = explode(',', $filterSettings['filter_rows']);
			$allowedFields = [];
			foreach ($fields as $fieldName => $fieldValue)
			{
				$isFieldAllowed = in_array($fieldName, $filterRows, true);
				if (!$isFieldAllowed)
				{
					$rowsFromFields = Options::getRowsFromFields([$fieldName => '']);
					$isFieldAllowed = in_array(reset($rowsFromFields), $filterRows, true);
				}
				if ($isFieldAllowed)
				{
					$allowedFields[$fieldName] = $fieldValue;
				}
			}

			if (count($allowedFields) < count($fields))
			{

				$filterOptions->setFilterSettings(
					$presetId,
					['fields' => $allowedFields],
					false,
					false,
				);
			}
		}

		parent::setFilterPreset($presetId, $filterOptions);
	}

	protected function getFilterOptions(): Options
	{
		if (!$this->filterOptions)
		{
			$filterOptions = new Options($this->getGridId(), $this->getFilterPresets());
			$this->getFieldRestrictionManager()->removeRestrictedFields($filterOptions);
			$this->filterOptions = $filterOptions;
		}

		return $this->filterOptions;
	}

	protected function getFilterPresets(): array
	{
		if ($this->getEntityTypeId() === \CCrmOwnerType::Company)
		{
			return (new Company())->getDefaultPresets();
		}

		if ($this->getEntityTypeId() === \CCrmOwnerType::Contact)
		{
			return (new Contact())->getDefaultPresets();
		}

		return [];
	}

	protected function buildParameters(array $filter = [], ?PageNavigation $pageNavigation = null): array
	{
		$params = [
			'limit' => self::LIMIT,
			'offset' => $pageNavigation ? $pageNavigation->getOffset() : 0,
		];

		if (!empty($filter))
		{
			$params['filter'] = $filter;
		}
		else
		{
			$params['filter'] = $this->getFilterParams();
		}

		if (empty($params['order']))
		{
			$params['order'] = ['ID' => 'DESC'];
		}

		return $params;
	}

	protected function getPreparedItems(array $parameters = []): array
	{
		$entityTypeId = $this->getEntityTypeId();
		$fieldsCollection = $this->getFieldsCollection();
		$fieldsMap = $this->getFieldsMap();

		$displayedFields = $this->getDisplayedFields($fieldsCollection, $fieldsMap);

		$parameters['select'] = $this->getSelectFields($displayedFields);
		$items = $this->getItems($entityTypeId, $parameters);
		$this->appendRelatedEntitiesValues($items);
		$this->prepareActivityCounters($items);

		return $items;
	}

	protected function getFieldsCollection(): Collection
	{
		if ($this->fieldsCollection === null)
		{
			$fieldsCollection = clone $this->getFactory($this->getEntityTypeId())->getFieldsCollection();
			foreach ($this->getClientProviders() as $clientProvider)
			{
				$fieldsCollection->merge($clientProvider->getFieldsCollection());
			}

			$this->fieldsCollection = $fieldsCollection;
		}

		return $this->fieldsCollection;
	}

	protected function getFieldsMap(): array
	{
		if ($this->fieldsMap === null)
		{
			$this->fieldsMap = array_flip($this->getFactory($this->getEntityTypeId())->getFieldsMap());
		}

		return $this->fieldsMap;
	}

	protected function getFactory(int $entityTypeId): Factory
	{
		if (empty($this->factories[$entityTypeId]))
		{
			$this->factories[$entityTypeId] = Container::getInstance()->getFactory($entityTypeId);
		}

		return $this->factories[$entityTypeId];
	}

	protected function getDisplayedFields(Collection $fieldsCollection, array $fieldsMap): array
	{
		$showedFields = $this->getShowedFieldsList($fieldsCollection, $fieldsMap);

		$results = [];
		foreach ($showedFields as $displayFieldName)
		{
			$results[$displayFieldName] =
				$this
					->createField($displayFieldName, $fieldsCollection)
					->setContext(Field::MOBILE_CONTEXT) // @todo need more flexible just like in kanban
			;
		}

		return $results;
	}

	protected function getShowedFieldsList(Collection $fieldsCollection, array $fieldsMap): array
	{
		if (empty($this->showedFieldsList))
		{
			$showedFieldsNames = $this->getShowedFieldsNames($fieldsCollection);

			$this->showedFieldsList = [];
			foreach ($showedFieldsNames as $showedFieldName)
			{
				$showedFieldName = trim($showedFieldName);
				$showedFieldName = ($fieldsMap[$showedFieldName] ?? $showedFieldName);

				if (!empty($showedFieldName) && $fieldsCollection->hasField($showedFieldName))
				{
					$this->showedFieldsList[] = $showedFieldName;
				}
			}
		}

		return $this->showedFieldsList;
	}

	protected function getShowedFieldsNames(Collection $fieldsCollection): array
	{
		$options = $this->getCurrentOptions();

		if ($options['columns'] === '' || $options['columns'] === null)
		{
			$visibleFieldsNames = [];
			foreach ($fieldsCollection as $field)
			{
				$showUserFieldInList = ($field->isUserField() && $field->getUserField()['SHOW_IN_LIST'] === 'Y');
				if (
					$showUserFieldInList
					|| in_array($field->getName(), self::DEFAULT_SHOW_FIELD_NAMES)
				)
				{
					$visibleFieldsNames[] = $field->getName();
				}
			}
		}
		else
		{
			$visibleFieldsNames = explode(',', $options['columns']);
		}

		$this->addFieldAliases($visibleFieldsNames);

		return $visibleFieldsNames;
	}

	protected function getCurrentOptions(): array
	{
		$options = $this->getOptions();

		return ($options ? $options['views'][$options['current_view']] : []);
	}

	/**
	 * @return array|false|mixed
	 */
	protected function getOptions()
	{
		return $this->getGridOptions()->GetOptions();
	}

	protected function getGridOptions(): \Bitrix\Main\Grid\Options
	{
		if (!($this->gridOptions instanceof Options))
		{
			$this->gridOptions = new \Bitrix\Main\Grid\Options($this->getGridId(), $this->getFilterPresets());
		}

		return $this->gridOptions;
	}

	protected function addFieldAliases(array &$fieldNames): void
	{
		foreach ($this->getFieldAliases() as $name => $alias)
		{
			$index = array_search($name, $fieldNames, true);
			if ($index !== false)
			{
				unset($fieldNames[$index]);
				$fieldNames[] = $alias;
			}
		}
	}

	protected function getFieldAliases(): array
	{
		if ($this->getEntityTypeId() === \CCrmOwnerType::Company)
		{
			return [
				'ASSIGNED_BY' => 'ASSIGNED_BY_ID',
				'CREATED_BY' => 'CREATED_BY_ID',
				'MODIFY_BY' => 'MODIFY_BY_ID',
			];
		}

		if ($this->getEntityTypeId() === \CCrmOwnerType::Contact)
		{
			return [
				'ASSIGNED_BY' => 'ASSIGNED_BY_ID',
				'CREATED_BY' => 'CREATED_BY_ID',
				'MODIFY_BY' => 'MODIFY_BY_ID',
			];
		}

		return [];
	}

	protected function createField(string $fieldName, Collection $fieldsCollection): Field
	{
		$field = $fieldsCollection->getField($fieldName);
		if (!$field)
		{
			throw new FieldNotFoundException('Field: ' . $fieldName . ' not found');
		}

		$fieldId = $field->getName();
		if (!empty($field->getUserField()))
		{
			return Field::createFromUserField($fieldId, $field->getUserField());
		}

		$displayField = (Field::createByType($field->getType(), $fieldId))->setTitle($field->getTitle());

		foreach ($field->getSettings() as $name => $setting)
		{
			$displayField->addDisplayParam($name, $setting);
		}

		if ($entityType = $field->getCrmStatusType())
		{
			$displayField->addDisplayParam('ENTITY_TYPE', $entityType);
		}

		if ($valueType = $field->getValueType())
		{
			$displayField->addDisplayParam('VALUE_TYPE', $valueType);
		}

		return $displayField;
	}

	protected function getSelectFields(array $displayedFields = []): array
	{
		$displayedFieldNames = array_keys($displayedFields);
		foreach ($this->getClientProviders() as $clientProvider)
		{
			$clientProvider->prepareSelect($displayedFieldNames);
		}

		return array_unique([
			...$displayedFieldNames,
			...$this->getDefaultSelectFieldNames(),
		]);
	}

	protected function getDefaultSelectFieldNames(): array
	{
		if ($this->getEntityTypeId() === \CCrmOwnerType::Company)
		{
			return [
				'DATE_CREATE',
				'ID',
				'TITLE',
				'PHONE',
				'EMAIL',
				'ASSIGNED_BY_ID',
			];
		}

		if ($this->getEntityTypeId() === \CCrmOwnerType::Contact)
		{
			return [
				'DATE_CREATE',
				'ID',
				'HONORIFIC',
				'NAME',
				'SECOND_NAME',
				'LAST_NAME',
				'COMPANY_ID',
				'PHONE',
				'EMAIL',
				'ASSIGNED_BY_ID',
			];
		}

		return [
			'TITLE',
		];
	}

	protected function getItems(int $entityTypeId, array $parameters): array
	{
		$requestFilter = $parameters['filter'] ?? [];

		$filter = [];
		$filterFields = $this->getFilterFieldsArray();
		$this->getProvider()->prepareListFilter($filter, $requestFilter);
		$this->getUfProvider()->prepareListFilter($filter, $filterFields, $requestFilter);
		$this->prepareActivityFilter($filter);
		$this->prepareEntityTypeFilter($filter);
		$this->prepareCategoryFilter($filter, $requestFilter);
		$this->prepareClientFilter($filter, $filterFields, $requestFilter);
		$parameters['filter'] = $this->getProvider()->prepareFilterValue($filter);

		$items = $this->getFactory($entityTypeId)->getItemsFilteredByPermissions($parameters);

		return $this->getFormattedItemsResults($items);
	}

	protected function getFilterFieldsArray(): array
	{
		$entityFilter = \Bitrix\Crm\Filter\Factory::createEntityFilter(
			\Bitrix\Crm\Filter\Factory::createEntitySettings($this->getEntityTypeId(), $this->getGridId()),
		);

		return $entityFilter->getFieldArrays();
	}

	protected function prepareActivityFilter(&$filter): void
	{
		if (isset($filter['=ACTIVITY_COUNTER']))
		{
			if (is_array($filter['=ACTIVITY_COUNTER']))
			{
				$counterTypeId = EntityCounterType::joinType(
					array_filter($filter['=ACTIVITY_COUNTER'], 'is_numeric')
				);
			}
			else
			{
				$counterTypeId = (int)$filter['=ACTIVITY_COUNTER'];
			}

			if ($counterTypeId > 0)
			{
				$counterUserIds = [];
				if (isset($filter['=ASSIGNED_BY_ID']))
				{
					if (is_array($filter['=ASSIGNED_BY_ID']))
					{
						$counterUserIds = array_filter($filter['=ASSIGNED_BY_ID'], 'is_numeric');
					}
					elseif ($filter['=ASSIGNED_BY_ID'] > 0)
					{
						$counterUserIds[] = (int)$filter['=ASSIGNED_BY_ID'];
					}
				}

				$counter = EntityCounterFactory::create($this->getEntityTypeId(), $counterTypeId);
				$filter['@ID'] = new SqlExpression($counter->getEntityListSqlExpression([
					'USER_IDS' => $counterUserIds,
				]));

				unset($filter['=ASSIGNED_BY_ID'], $filter['=ACTIVITY_COUNTER']);
			}
		}
	}

	protected function prepareEntityTypeFilter(array &$filter): void
	{
		if ($this->getEntityTypeId() === \CCrmOwnerType::Company)
		{
			$filter['=IS_MY_COMPANY'] = 'N';
		}
	}

	protected function prepareCategoryFilter(array &$filter, array $requestFilter): void
	{
		if (isset($requestFilter['CATEGORY_ID']))
		{
			$category = $this->initCategory((int)$requestFilter['CATEGORY_ID']);
			if ($category)
			{
				$filter = $category->getItemsFilter($filter);
			}
		}
	}

	protected function prepareClientFilter(array &$filter, array $filterFields, array $requestFilter): void
	{
		foreach ($this->getClientFilterProviders() as $clientDataProvider)
		{
			if ($clientDataProvider instanceof ClientDataProvider)
			{
				$clientDataProvider->prepareListFilter($filter, $requestFilter);
			}
			elseif ($clientDataProvider instanceof ClientUserFieldDataProvider)
			{
				$clientDataProvider->prepareListFilter($filter, $filterFields, $requestFilter);
			}
		}
	}

	protected function getFormattedItemsResults(array $items = []): array
	{
		$results = [];
		foreach ($items as $item)
		{
			$results[$item->getId()] = $this->getItemData($item);
		}

		return $results;
	}

	protected function getItemData(Item $item): array
	{
		if (
			$this->getEntityTypeId() === \CCrmOwnerType::Company
			|| $this->getEntityTypeId() === \CCrmOwnerType::Contact
		)
		{
			return array_merge(
				$item->getData(),
				[
					'FM' => $item->getFm(),
				]
			);
		}

		return $item->getData();
	}

	protected function appendRelatedEntitiesValues(array &$items): void
	{
		foreach ($this->getClientProviders() as $clientProvider)
		{
			$clientProvider->appendResult($items);
		}

		if ($this->entityTypeId !== \CCrmOwnerType::Company && $this->entityTypeId !== \CCrmOwnerType::Contact)
		{
			return;
		}

		if ($this->entityTypeId === \CCrmOwnerType::Company)
		{
			$clientType = \CCrmOwnerType::ContactName;
			$companyFactory = $this->getFactory(\CCrmOwnerType::Company);

			$companyIdsMap = [];

			foreach ($items as $item)
			{
				$contactItems = $companyFactory->getItem($item['ID'])->getContacts();
				foreach ($contactItems as $contactItem)
				{
					$companyIdsMap[$contactItem->getId()] = $item['ID'];
				}
			}

			$contacts = $this->getAccessibleClients(array_keys($companyIdsMap), \CCrmOwnerType::Contact);
			foreach ($contacts as $contact)
			{
				$itemId = $companyIdsMap[$contact['ID']];
				$contactInfo = Client\Info::get($contact, $clientType, $contact['TITLE'], false);
				$items[$itemId][$clientType][] = array_merge(...$contactInfo[mb_strtolower($clientType)]);
			}
		}

		if ($this->entityTypeId === \CCrmOwnerType::Contact)
		{
			$companiesIds = [];
			foreach ($items as $item)
			{
				if (!empty($item['COMPANY_ID']))
				{
					$companiesIds[] = $item['COMPANY_ID'];
				}
			}

			$contacts = $this->getAccessibleClients($companiesIds, \CCrmOwnerType::Company);
			$clientType = \CCrmOwnerType::CompanyName;

			foreach ($items as &$item)
			{
				if (empty($item['COMPANY_ID']))
				{
					continue;
				}

				$companyId = $item['COMPANY_ID'];
				if (isset($contacts[$companyId]))
				{
					$company = $contacts[$companyId];
					$company['ID'] = $companyId;

					$companyInfo = Client\Info::get($company, $clientType, $company['TITLE'], false);
					$item[$clientType] = $companyInfo[mb_strtolower($clientType)];
				}
			}
		}

		unset($item);
	}

	protected function getAccessibleClients(array $ids, int $entityTypeId): array
	{
		if (empty($ids))
		{
			return [];
		}

		$isCompany = $entityTypeId === \CCrmOwnerType::Company;
		$parameters = [
			'filter' => [
				'@ID' => $ids,
			],
		];

		if ($isCompany)
		{
			$parameters['select'] = [
				'ID',
				'TITLE',
			];
		}

		$clients = Container::getInstance()
			->getFactory($entityTypeId)
			->getItemsFilteredByPermissions($parameters)
		;

		$accessibleEntities = [];
		foreach ($clients as $client)
		{
			$accessibleEntities[$client->getId()] = [
				'ID' => $client->getId(),
				'TITLE' => $isCompany ? $client->getTitle() : $client->getFormattedName(),
				'FM' => $client->getFm(),
			];
		}

		return $accessibleEntities;
	}

	public function prepareFilterPresets(Entity $entity, array $presets, ?string $defaultPresetName): array
	{
		$preparedPresets = parent::prepareFilterPresets($entity, $presets, $defaultPresetName);
		$availableFields = array_keys(array_merge($entity->getBaseFields(), $entity->getUserFields()));

		$this->markUnsupportedPresetsAsDisabled($preparedPresets, $presets, $availableFields);

		return $preparedPresets;
	}

	private function markUnsupportedPresetsAsDisabled(
		array &$preparedPresets,
		array $presets,
		array $availableFields
	): void
	{
		$preparedPresetIds = array_column($preparedPresets, 'id');

		foreach ($presets as $presetId => $preset)
		{
			if (empty($preset['fields']))
			{
				continue;
			}

			$fieldNames = $this->getPresetFilterFieldNames($preset['fields']);
			$unsupportedFieldIds = array_diff($fieldNames, $availableFields);
			if (empty($unsupportedFieldIds))
			{
				continue;
			}

			$foundKey = array_search($presetId, $preparedPresetIds, true);
			if (!$foundKey)
			{
				continue;
			}

			$preparedPresets[$foundKey]['disabled'] = true;
			$preparedPresets[$foundKey]['unsupportedFields'] = $this->getUnsupportedFields($unsupportedFieldIds);
		}
	}

	private function getPresetFilterFieldNames(array $presetFields): array
	{
		$fieldNames = [];
		$this->getProvider()->prepareListFilter($fieldNames, $presetFields);
		$this->getUfProvider()->prepareListFilter($fieldNames, $this->getFilterFieldsArray(), $presetFields);

		return array_map(
			static fn($fieldName): string => \CSqlUtil::GetFilterOperation($fieldName)['FIELD'],
			array_keys($fieldNames),
		);
	}

	private function getUnsupportedFields(array $fieldIds): array
	{
		$filterSettings = $this->getProvider()->getSettings();
		$filter = \Bitrix\Crm\Filter\Factory::createEntityFilter($filterSettings);

		$result = [];
		foreach ($fieldIds as $fieldId)
		{
			$field = $filter->getField($fieldId);
			if ($field)
			{
				$result[] = [
					'id' => $fieldId,
					'name' => $field->getName(),
				];
			}
		}

		return $result;
	}

	protected function initCategory(?int $categoryId = null): ?Category
	{
		$factory = $this->getFactory($this->getEntityTypeId());
		if ($categoryId <= 0)
		{
			return ($factory->isCategoriesEnabled() ? null : $factory->createDefaultCategoryIfNotExist());
		}

		$category = $factory->getCategory($categoryId);

		return ($category ?? null);
	}
}
