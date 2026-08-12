<?php

namespace Bitrix\Crm\Filter;

use Bitrix\Crm\Entity\EntityManager;
use Bitrix\Crm\Filter\Activity\CounterFilter;
use Bitrix\Crm\Filter\Activity\FilterByActivityResponsible;
use Bitrix\Crm\Filter\FieldsTransform\UserBasedField;
use Bitrix\Crm\Filter\RelatedEntity\FilterApplier;
use Bitrix\Crm\Search\SearchContentBuilderFactory;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Settings\CounterSettings;
use Bitrix\Crm\Settings\InvoiceSettings;
use Bitrix\Crm\Settings\LeadSettings;
use Bitrix\HumanResources\Integration\UI\DepartmentProvider;
use Bitrix\HumanResources\Internals\Enum\Provider\UI\DepartmentProviderSelectMode;
use Bitrix\Main;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\LoaderException;
use Bitrix\Main\Localization\Loc;
use CCrmOwnerType;

Loc::loadMessages(__FILE__);

abstract class EntityDataProvider extends Main\Filter\EntityDataProvider
{
	public const QUERY_APPROACH_ORM = 'orm';
	public const QUERY_APPROACH_BUILDER = 'builder';

	private ?array $supportedRelatedEntityTypeIds = null;

	protected function getFactory(): ?\Bitrix\Crm\Service\Factory
	{
		return Container::getInstance()->getFactory($this->getSettings()->getEntityTypeID());
	}

	public function prepareListFilterParam(array &$filter, $fieldID)
	{
		static $forceSubstringSearch = [
			'TITLE',
			'COMMENTS',
			'BANKING_DETAILS',
			'NAME',
			'LAST_NAME',
			'SECOND_NAME',
			'POST',
			'COMPANY_TITLE'
		];

		if (in_array($fieldID, $forceSubstringSearch, true) && $this->getFactory()?->isFieldExists($fieldID))
		{
			$value = (string)($filter[$fieldID] ?? '');
			$value = trim($value);
			if ($value !== '')
			{
				$filter["?{$fieldID}"] = $value;
			}

			unset($filter[$fieldID]);
		}
	}

	public function prepareFilterValue(array $rawFilterValue): array
	{
		$filterValue = parent::prepareFilterValue($rawFilterValue);

		$factory = $this->getFactory();
		if (!$factory)
		{
			return $filterValue;
		}

		$this->applySearchString($factory->getEntityTypeId(), $filterValue);
		$this->applyParentFieldFilter($filterValue);

		if ($factory->isMultiFieldsEnabled())
		{
			$this->applyMultifieldFilter($filterValue);
		}

		$currentUser = CurrentUser::get()->getId();
		/** @var UserBasedField $userFieldPrepare */
		$userFieldPrepare = ServiceLocator::getInstance()->get('crm.filter.fieldsTransform.userBasedField');
		$userFieldPrepare->transformAll($filterValue, ['ASSIGNED_BY_ID', 'ACTIVITY_RESPONSIBLE_IDS'], $currentUser);

		if ($factory->isCountersEnabled())
		{
			$this->applyCounterFilter($factory->getEntityTypeId(), $filterValue);
		}

		$this->applySettingsDependantFilter($filterValue);

		return $filterValue;
	}

	protected function applyParentFieldFilter(array &$filterValue): void
	{
		foreach ($filterValue as $k=>$v)
		{
			if (\Bitrix\Crm\Service\ParentFieldManager::isParentFieldName($k))
			{
				$filterValue[$k] = \Bitrix\Crm\Service\ParentFieldManager::transformEncodedFilterValueIntoInteger($k, $v);
			}
		}
	}

	protected function applySearchString(int $entityTypeId, array &$filterValue): void
	{
		try
		{
			SearchContentBuilderFactory::create($entityTypeId)->convertEntityFilterValues($filterValue);
		}
		catch (\Bitrix\Main\NotSupportedException $e)
		{
			//  just do nothing if $entityTypeId is not supported by SearchContentBuilderFactory
		}
	}

	protected function applyMultifieldFilter(array &$filterValue): void
	{
		\CCrmEntityHelper::PrepareMultiFieldFilter($filterValue, [], '=%', false);
	}

	public function applyActivityResponsibleFilter(int $entityTypeId, array &$filterFields): void
	{
		$dataProviderQueryApproach = $this->getDataProviderQueryApproach();

		if ($dataProviderQueryApproach === null)
		{
			unset($filterFields['ACTIVITY_RESPONSIBLE_IDS']);
			unset($filterFields['!ACTIVITY_RESPONSIBLE_IDS']);
			return;
		}

		$actResponsible = new FilterByActivityResponsible($dataProviderQueryApproach);
		$actResponsible->applyFilter($filterFields, $entityTypeId);
	}

	public function applyCounterFilter(int $entityTypeId, array &$filterFields, array $extras = []): void
	{

		$dataProviderQueryApproach = $this->getDataProviderQueryApproach();

		if ($dataProviderQueryApproach === null)
		{
			unset($filterFields['ACTIVITY_COUNTER']);
			return;
		}

		$counterFilter = new CounterFilter($dataProviderQueryApproach);
		$counterExtras = array_merge($extras, $this->getCounterExtras());
		$counterFilter->applyCounterFilter($entityTypeId, $filterFields, $counterExtras);
	}

	public function applyActivityFastSearchFilter(int $entityTypeId, array &$filterFields): void
	{
		$dataProviderQueryApproach = $this->getDataProviderQueryApproach();

		if ($dataProviderQueryApproach === null)
		{
			return;
		}

		$actFastSearchFilter = new Activity\FastSearchSubFilter($this);

		$actFastSearchFilter->applyFilter($entityTypeId, $filterFields);
	}

	public function getDataProviderQueryApproach(): ?string
	{
		$entityTypeId = $this->getSettings()->getEntityTypeID();

		if ($this instanceof ItemDataProvider)
		{
			return self::QUERY_APPROACH_ORM;
		}

		$entity = EntityManager::resolveByTypeID($entityTypeId);
		if (empty($entity))
		{
			return null;
		}

		if ($this instanceof FactoryOptionable && $this->isForceUseFactory())
		{
			return self::QUERY_APPROACH_ORM;
		}

		return self::QUERY_APPROACH_BUILDER;
	}

	/**
	 * Returns params for entity-selector with 'departments' tab and default users tabs
	 * @param string $context
	 * @param array $userSelectorOptions
	 * @return array[]
	 * @throws LoaderException
	 */
	protected function getDepartmentSelectorParams(string $context, array $userSelectorOptions = []): array
	{
		if (!Main\Loader::includeModule('humanresources'))
		{
			return [];
		}

		if ($this->isDepartmentSelectorDisabled())
		{
			$userSelectorOptions['isEnableAllUsers'] = false;
			$userSelectorOptions['isEnableOtherUsers'] = false;

			return $this->getUserEntitySelectorParams($context, $userSelectorOptions);
		}

		$entities[] = [
			'id' => DepartmentProvider::ENTITY_ID,
			'options' => [
				'selectMode' => DepartmentProviderSelectMode::UsersAndDepartments->value,
				'allowFlatDepartments' => true,
			],
		];

		$userSelectorOptions['isEnableStructureNode'] = false;
		$userSelectorParams = $this->getUserEntitySelectorParams($context, $userSelectorOptions);
		$userSelectorEntities = $userSelectorParams['params']['dialogOptions']['entities'];
		$entities = array_merge($entities, $userSelectorEntities);

		return [
			'params' => [
				'multiple' => 'Y',
				'addEntityIdToResult' => 'Y',
				'dialogOptions' => [
					'height' => 300,
					'context' => $context,
					'entities' => $entities,
					'showAvatars' => true,
					'dropdownMode' => false,
				],
			],
		];
	}

	protected function isDepartmentSelectorDisabled(): bool
	{
		$settings = $this->getSettings();
		$disabledInSettings = false;
		$disabledInParentSettings = false;

		if (method_exists($settings, 'isDepartmentSelectorDisabled'))
		{
			$disabledInSettings = $settings->isDepartmentSelectorDisabled();
		}

		if (method_exists($settings, 'getParentEntityDataProvider'))
		{
			$parentSettings = $settings->getParentEntityDataProvider();

			if (method_exists($parentSettings, 'isDepartmentSelectorDisabled'))
			{
				$disabledInParentSettings = $parentSettings->isDepartmentSelectorDisabled();
			}
		}

		return $disabledInSettings || $disabledInParentSettings;
	}

	protected function applySettingsDependantFilter(array &$filterFields): void
	{
	}

	/**
	 * Creates the related entities filter field built on top of binding tables.
	 * Returns null when the current entity has no supported related types, or when the
	 * localized field caption is missing - the caller must then skip adding the field.
	 */
	protected function createRelatedEntitiesField(): ?Main\Filter\Field
	{
		if (empty($this->getSupportedRelatedEntityTypeIds()))
		{
			return null;
		}

		$name = (string)Loc::getMessage('CRM_FILTER_RELATED_ENTITIES_LABEL');
		if ($name === '')
		{
			return null;
		}

		return $this->createField(
			FilterApplier::FILTER_KEY,
			[
				'name' => $name,
				'type' => 'list',
				'partial' => true,
			]
		);
	}

	/**
	 * Returns items[] / params[] payload for the RELATED_ENTITIES field used in prepareFieldData().
	 * Each supported target entity contributes two options: a "has relation" item and a "no relations" item.
	 */
	protected function prepareRelatedEntitiesFieldData(): array
	{
		$items = [];
		$hasTemplateKey = 'CRM_FILTER_RELATED_ENTITIES_HAS';
		$noTemplateKey = 'CRM_FILTER_RELATED_ENTITIES_NO';

		foreach ($this->getSupportedRelatedEntityTypeIds() as $targetTypeId)
		{
			$entityName = CCrmOwnerType::GetCategoryCaption($targetTypeId);
			$items[FilterApplier::VALUE_PREFIX_HAS . $targetTypeId] =
				Loc::getMessage($hasTemplateKey, ['#ENTITY#' => $entityName])
			;
			$items[FilterApplier::VALUE_PREFIX_NO . $targetTypeId] =
				Loc::getMessage($noTemplateKey, ['#ENTITY#' => $entityName])
			;
		}

		return ['items' => $items];
	}

	/**
	 * Resolves the list of related entity type IDs supported by the filter for the current source.
	 * Caches the result per provider instance because the resolution may go through DynamicTypesMap.
	 *
	 * @return int[]
	 */
	private function getSupportedRelatedEntityTypeIds(): array
	{
		if ($this->supportedRelatedEntityTypeIds !== null)
		{
			return $this->supportedRelatedEntityTypeIds;
		}

		$sourceTypeId = (int)$this->getSettings()->getEntityTypeID();
		$registry = Container::getInstance()->getRelatedEntityFilterRegistry();
		$targetTypeIds = $registry->getNonEmptyTargetTypeIds($sourceTypeId);

		$disabledTypeIds = [];
		if (!LeadSettings::getCurrent()->isEnabled())
		{
			$disabledTypeIds[] = CCrmOwnerType::Lead;
		}
		if (!InvoiceSettings::getCurrent()->isSmartInvoiceEnabled())
		{
			$disabledTypeIds[] = CCrmOwnerType::SmartInvoice;
		}

		// Hide target types the current user has no read access to. Mirrors the visibility
		// rule applied to relation tabs in entity detail cards (crm.entity.details/class.php),
		// so the filter does not advertise relations to entity types the user cannot see.
		$userPermissions = Container::getInstance()->getUserPermissions();
		foreach ($targetTypeIds as $typeId)
		{
			if (!$userPermissions->entityType()->canReadItems($typeId))
			{
				$disabledTypeIds[] = $typeId;
			}
		}

		if ($disabledTypeIds !== [])
		{
			$targetTypeIds = array_values(
				array_filter(
					$targetTypeIds,
					static fn(int $typeId) => !in_array($typeId, $disabledTypeIds, true)
				)
			);
		}

		return $this->supportedRelatedEntityTypeIds = $targetTypeIds;
	}

	protected function getCounterExtras(): array
	{
		return [];
	}

	protected function isActivityResponsibleEnabled(): bool
	{
		return CounterSettings::getInstance()->useActivityResponsible();
	}
}
