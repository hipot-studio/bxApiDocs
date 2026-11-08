<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\Bizproc\Automation\Engine\ConditionGroup;
use Bitrix\Bizproc\Activity\PropertiesDialog;
use Bitrix\Bizproc\FieldType;
use Bitrix\Bizproc\Public\Activity\Interface\NodeFilterMetadataProvider;
use Bitrix\Crm;
use Bitrix\Crm\Integration\BizProc\NodeFilter\RelatedEntityTypeProvider;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * @property-write string Title
 * @property-write int DynamicTypeId
 * @property-write int DynamicId
 * @property-write array DynamicFilterFields
 * @property-write array ReturnFields
 * @property-write string OnlyDynamicEntities
 * @property-write array|null DynamicEntityFields
 */
class CBPCrmGetDynamicInfoActivity extends \Bitrix\Bizproc\Activity\BaseActivity implements NodeFilterMetadataProvider
{
	use \Bitrix\Bizproc\Activity\Mixins\EntityFilter;

	protected static $requiredModules = ['crm'];

	private const RETURN_PROPERTY_DOCUMENT = 'Document';

	private const SUPPORTED_FILTER_FIELD_TYPES = [
		FieldType::DOUBLE,
		FieldType::INT,
		FieldType::USER,
		FieldType::STRING,
		FieldType::BOOL,
		FieldType::SELECT,
		FieldType::DATE,
		FieldType::DATETIME,
		FieldType::TEXT,
	];

	public function __construct($name)
	{
		parent::__construct($name);
		$this->arProperties = [
			'Title' => '',
			'DynamicTypeId' => 0,
			'DynamicFilterFields' => ['items' => []],
			'ReturnFields' => [],
			'OnlyDynamicEntities' => 'N',

			// return
			'DynamicEntityFields' => null,
		];

		$this->setPropertiesTypes([
			'DynamicTypeId' => ['Type' => FieldType::INT],
		]);
	}

	protected function prepareProperties(): void
	{
		parent::prepareProperties();

		try
		{
			$this->preparedProperties['DynamicId'] = $this->findEntityId();
		}
		catch (\Bitrix\Main\ArgumentException $exception)
		{
			$this->preparedProperties['DynamicId'] = 0;
		}
	}

	protected function findEntityId(): int
	{
		$dynamicTypeId = $this->DynamicTypeId;
		if (!$dynamicTypeId)
		{
			return 0;
		}

		$targetDocumentType = CCrmBizProcHelper::ResolveDocumentType($dynamicTypeId);
		$factory = Container::getInstance()->getFactory($dynamicTypeId);
		$items = [];
		if (isset($factory))
		{
			$conditionGroup = new ConditionGroup($this->DynamicFilterFields);
			$conditionGroup->internalizeValues($targetDocumentType);

			$items = $factory->getItems([
				'select' => ['ID'],
				'filter' => $this->getOrmFilter($conditionGroup, $targetDocumentType),
				'limit' => 1,
			]);
		}

		return $items ? $items[0]->getId() : 0;
	}

	protected function checkProperties(): \Bitrix\Main\ErrorCollection
	{
		$errors = parent::checkProperties();

		if (!CCrmBizProcHelper::ResolveDocumentName($this->DynamicTypeId))
		{
			$errors->setError(new Error(Loc::getMessage('CRM_GDIA_ENTITY_TYPE_ERROR')));
		}
		if ($this->DynamicId <= 0)
		{
			$errors->setError(new Error(Loc::getMessage('CRM_GDIA_ENTITY_EXISTENCE_ERROR')));
		}

		return $errors;
	}

	protected function internalExecute(): \Bitrix\Main\ErrorCollection
	{
		$errors = parent::internalExecute();

		$complexDocumentId = CCrmBizProcHelper::ResolveDocumentId($this->DynamicTypeId, $this->DynamicId);

		if (defined('\CBPDocument::PARAM_USED_DOCUMENT_FIELDS'))
		{
			$document = static::getDocumentService()->GetDocument($complexDocumentId, select: $this->ReturnFields);
		}
		else
		{
			$document = static::getDocumentService()->GetDocument($complexDocumentId);
		}

		foreach ($this->ReturnFields as $fieldId)
		{
			$this->arProperties[$fieldId] = $document[$fieldId] ?? null;
			$this->preparedProperties[$fieldId] = $document[$fieldId] ?? null;
		}

		$this->arProperties[self::RETURN_PROPERTY_DOCUMENT] = $complexDocumentId;
		$this->preparedProperties[self::RETURN_PROPERTY_DOCUMENT] = $complexDocumentId;

		$this->setPropertiesTypes($this->DynamicEntityFields);

		return $errors;
	}

	protected function reInitialize()
	{
		parent::reInitialize();
		if (is_array($this->ReturnFields))
		{
			foreach ($this->ReturnFields as $fieldId)
			{
				$this->arProperties[$fieldId] = null;
				$this->preparedProperties[$fieldId] = null;
			}
		}
	}

	protected static function getFileName(): string
	{
		return __FILE__;
	}

	protected static function extractPropertiesValues(PropertiesDialog $dialog, array $fieldsMap): Result
	{
		$simpleMap = $fieldsMap;
		unset($simpleMap['DynamicFilterFields']);
		$result = parent::extractPropertiesValues($dialog, $simpleMap);

		if ($result->isSuccess())
		{
			$currentValues = $result->getData();
			$entityTypeId = (int)$currentValues['DynamicTypeId'];

			$currentValues['DynamicFilterFields'] = static::extractFilterFromProperties($dialog, $fieldsMap)->getData();
			$currentValues['ReturnFields'] = $dialog->getCurrentValue('return_fields', []);

			$returnFieldsMap = static::getReturnFieldsMap($entityTypeId);
			foreach ($dialog->getCurrentValue('return_fields', []) as $fieldId)
			{
				if (isset($returnFieldsMap[$fieldId]))
				{
					$currentValues['DynamicEntityFields'][$fieldId] = $returnFieldsMap[$fieldId];
				}
			}

			if (defined('\Bitrix\Bizproc\FieldType::DOCUMENT'))
			{
				$currentValues['DynamicEntityFields'][self::RETURN_PROPERTY_DOCUMENT] = [
					'Type' => FieldType::DOCUMENT,
					'Name' => \CCrmOwnerType::GetDescription($entityTypeId),
					'Default' => \CCrmBizProcHelper::ResolveDocumentType($entityTypeId),
				];
			}

			$result->setData($currentValues);
		}

		return $result;
	}

	public static function GetPropertiesDialog(
		$documentType,
		$activityName,
		$workflowTemplate,
		$workflowParameters,
		$workflowVariables,
		$currentValues = null,
		$formName = '',
		$popupWindow = null,
		$siteId = ''
	)
	{
		$dialog = parent::GetPropertiesDialog(...func_get_args());
		$dialog->setRuntimeData([
			'DocumentName' => static::getDocumentService()->getEntityName('crm', $documentType[1]),
			'DocumentFields' => array_values(Crm\Automation\Helper::getDocumentFields($documentType)),
		]);

		return $dialog;
	}

	protected static function getReturnFieldsMap(int $entityTypeId): array
	{
		$fieldsMap = [];
		$documentType = CCrmBizProcHelper::ResolveDocumentType($entityTypeId);

		try
		{
			if (isset($documentType))
			{
				$fieldsMap = array_filter(
					static::getDocumentService()->GetDocumentFields($documentType),
					static function ($fieldId) {
						return !static::isInternalField($fieldId);
					},
					ARRAY_FILTER_USE_KEY
				);
			}
		}
		catch (\Bitrix\Main\ArgumentException $exception) {}

		return $fieldsMap;
	}

	public static function getPropertiesDialogMap(?\Bitrix\Bizproc\Activity\PropertiesDialog $dialog = null): array
	{
		$typeNames = [];
		$filteringFieldsMap = [];
		$returnFieldsMap = [];

		$typesMap = Container::getInstance()->getTypesMap();

		foreach ($typesMap->getFactories() as $factory)
		{
			$entityTypeId = $factory->getEntityTypeId();
			$documentType = CCrmBizProcHelper::ResolveDocumentType($entityTypeId);

			if (isset($documentType))
			{
				$typeNames[$entityTypeId] = static::getDocumentService()->getDocumentTypeName($documentType);
				$returnFieldsMap[$entityTypeId] = static::getReturnFieldsMap($entityTypeId);
				$filteringFieldsMap[$entityTypeId] = static::getFilteringFieldsMap($entityTypeId);
			}
		}

		$showOnlyDynamicEntities = static::showOnlyDynamicEntities($dialog);

		return [
			'DynamicTypeId' => [
				'Name' => Loc::getMessage('CRM_GDIA_TYPE_ID'),
				'FieldName' => 'dynamic_type_id',
				'Type' => FieldType::SELECT,
				'Options' => $showOnlyDynamicEntities ? static::getOnlyDynamicEntities($typeNames) : $typeNames,
				'Required' => true,
				'AllowSelection' => false,
			],
			'ReturnFields' => [
				'Name' => Loc::getMessage('CRM_GDIA_RETURN_FIELDS_SELECTION'),
				'FieldName' => 'return_fields',
				'Type' => FieldType::SELECT,
				'Options' => [],
				'Multiple' => true,
				'Required' => true,
				'Map' => $returnFieldsMap,
				'Getter' => function($dialog, $property, $currentActivity, $compatible) {
					return $currentActivity['Properties']['ReturnFields'];
				},
				'AllowSelection' => false,
			],
			'DynamicFilterFields' => [
				'Name' => Loc::getMessage('CRM_GDIA_FILTERING_FIELDS_PROPERTY'),
				'FieldName' => 'dynamic_filter_fields',
				'Map' => $filteringFieldsMap,
				'Getter' => function($dialog, $property, $currentActivity, $compatible) {
					return $currentActivity['Properties']['DynamicFilterFields'];
				},
			],
			'OnlyDynamicEntities' => [
				'FieldName' => 'only_dynamic_entities',
				'Type' => 'bool',
				'Default' => $showOnlyDynamicEntities ? 'Y' : 'N',
				'Settings' => [
					'Hidden' => true,
				],
			],
		];
	}

	protected static function getFilteringFieldsMap(int $entityTypeId): array
	{
		$documentType = CCrmBizProcHelper::ResolveDocumentType($entityTypeId);

		$factory = Container::getInstance()->getFactory($entityTypeId);
		$originalFieldsCollection = isset($factory) ? $factory->getFieldsCollection() : null;
		$dealParentFieldId = Crm\Service\ParentFieldManager::getParentFieldName(\CCrmOwnerType::Deal);

		$map = [];

		foreach (Crm\Automation\Helper::getDocumentFields($documentType) as $fieldId => $field)
		{
			if ($fieldId === 'OBSERVER_IDS')
			{
				$fieldId = Crm\Item::FIELD_NAME_OBSERVERS;
			}

			if ($fieldId === $dealParentFieldId)
			{
				$field = array_merge($field, [
					'Type' => FieldType::INT,
					'BaseType' => FieldType::INT,
					'Options' => null,
					'Multiple' => false,
				]);
			}

			$isEntityField = true;
			if (isset($originalFieldsCollection))
			{
				$isEntityField = $originalFieldsCollection->hasField($factory->getCommonFieldNameByMap($fieldId));
			}
			if (
				in_array($field['Type'], self::SUPPORTED_FILTER_FIELD_TYPES, true)
				&& !static::isInternalField($fieldId)
				&& $isEntityField
			)
			{
				$map[] = $field;
			}
		}

		return $map;
	}

	protected static function isInternalField(string $fieldId): bool
	{
		$internalFieldIds = array_merge(
			Crm\UtmTable::getCodeList(),
			[
				Crm\Item::FIELD_NAME_ACCOUNT_CURRENCY_ID,
				Crm\Item::FIELD_NAME_OPPORTUNITY_ACCOUNT,
				Crm\Item::FIELD_NAME_TAX_VALUE_ACCOUNT,
				Crm\Item::FIELD_NAME_WEBFORM_ID,
			]
		);

		return in_array($fieldId, $internalFieldIds, true);
	}

	private static function showOnlyDynamicEntities(?PropertiesDialog $dialog = null): bool
	{
		if (!$dialog)
		{
			return false;
		}

		$context = $dialog->getContext() ?? [];
		if (isset($context['addMenuGroup']) && $context['addMenuGroup'] === 'digitalWorkplace')
		{
			return true;
		}

		$workflowTemplate = $dialog->getWorkflowTemplate();
		$currentActivity = \CBPWorkflowTemplateLoader::FindActivityByName(
			$workflowTemplate,
			$dialog->getActivityName()
		);

		return (
			is_array($currentActivity)
			&& is_array($currentActivity['Properties'])
			&& $currentActivity['Properties']['OnlyDynamicEntities'] === 'Y'
		);
	}

	private static function getOnlyDynamicEntities(array $dynamicTypeIdOptions): array
	{
		return array_filter(
			$dynamicTypeIdOptions,
			static function($key) {
				return (CCrmOwnerType::isPossibleDynamicTypeId($key));
			},
			ARRAY_FILTER_USE_KEY
		);
	}

	public static function getNodeFilterMetadata(
		array $contextDocumentType,
		bool $onlyDynamicEntities = false,
		bool $includeRelatedEntityTypes = false,
		?string $contextActivityType = null,
	): array
	{
		$allEntityTypeOptions = [];
		foreach (Container::getInstance()->getTypesMap()->getFactories() as $factory)
		{
			$entityTypeId = $factory->getEntityTypeId();
			$documentType = CCrmBizProcHelper::ResolveDocumentType($entityTypeId);

			if (isset($documentType))
			{
				$allEntityTypeOptions[$entityTypeId] =
					static::getDocumentService()->getDocumentTypeName($documentType)
				;
			}
		}

		// Decided before the dynamic types: the node resolves an element of the context type or of a type
		// related to it, so a smart process outside the relation graph would be offered and resolve nothing.
		if ($includeRelatedEntityTypes)
		{
			$entityTypeOptions = [];
		}
		elseif ($onlyDynamicEntities)
		{
			$entityTypeOptions = array_filter(
				$allEntityTypeOptions,
				static fn(mixed $title, mixed $entityTypeId): bool
					=> CCrmOwnerType::isPossibleDynamicTypeId((int)$entityTypeId),
				ARRAY_FILTER_USE_BOTH,
			);
		}
		else
		{
			$entityTypeOptions = $allEntityTypeOptions;
		}

		if ($includeRelatedEntityTypes)
		{
			$entityTypeOptions = static::appendRelatedEntityTypeOptions(
				$entityTypeOptions,
				$allEntityTypeOptions,
				$contextDocumentType,
			);
		}

		$scope = \Bitrix\Crm\Integration\BizProc\NodeFilter\DynamicTypeScope::fromActivityType(
			$contextActivityType,
		);
		if ($scope !== null)
		{
			$entityTypeOptions = \Bitrix\Crm\Integration\BizProc\NodeFilter\DynamicTypeScopePolicy::getRequestInstance()
				->filterEntityTypeOptions($scope, $entityTypeOptions)
			;
		}

		$filterFieldsMap = [];
		$documentTypeMap = [];
		foreach (array_keys($entityTypeOptions) as $entityTypeId)
		{
			$filterFieldsMap[$entityTypeId] = static::getFilteringFieldsMap((int)$entityTypeId);

			$entityDocumentType = CCrmBizProcHelper::ResolveDocumentType((int)$entityTypeId);
			if (is_array($entityDocumentType))
			{
				$documentTypeMap[(string)$entityTypeId] = $entityDocumentType;
			}
		}

		return [
			'entityTypeOptions' => $entityTypeOptions,
			'filterFieldsMap' => $filterFieldsMap,
			'documentTypeMap' => $documentTypeMap,
			'entityTypeBadges' => static::getEntityTypeBadges($entityTypeOptions),
		];
	}

	/**
	 * Type::getCustomSectionId() holds an automated solution id despite its legacy name, and "no automated solution"
	 * is stored both as NULL and as 0: portals kept the 0 written by crm.type.update before e18cdcd60158.
	 *
	 * @return array<int|string, string>
	 */
	private static function getEntityTypeBadges(array $entityTypeOptions): array
	{
		$userPermissions = Container::getInstance()->getUserPermissions();

		// Read on the first type that needs a title, never for a list where nothing is bound. Indexed by ID:
		// the manager keys the list by INTRANET_CUSTOM_SECTION_ID and searches it by ID linearly.
		$automatedSolutionsById = null;

		$entityTypeBadges = [];
		foreach (array_keys($entityTypeOptions) as $entityTypeId)
		{
			$type = Container::getInstance()->getTypeByEntityTypeId((int)$entityTypeId);
			$automatedSolutionId = (int)($type?->getCustomSectionId() ?? 0);
			if ($automatedSolutionId <= 0)
			{
				continue;
			}

			// The badge names a section of the left menu, so it is given on the very condition that menu shows
			// the section: see CustomSectionProvider::isAvailable(). The list of types itself is not filtered by
			// permissions, and this map must not name what the menu keeps hidden.
			if (
				!$userPermissions->entityType()->canReadItems((int)$entityTypeId)
				&& !$userPermissions->dynamicType()->canUpdate((int)$entityTypeId)
			)
			{
				continue;
			}

			$automatedSolutionsById ??= array_column(
				Container::getInstance()->getAutomatedSolutionManager()->getExistingAutomatedSolutions(),
				null,
				'ID',
			);

			$title = trim((string)($automatedSolutionsById[$automatedSolutionId]['TITLE'] ?? ''));
			if ($title === '')
			{
				continue;
			}

			$entityTypeBadges[$entityTypeId] = $title;
		}

		return $entityTypeBadges;
	}

	/**
	 * Appends the context entity type and its related entity types to the entity type options,
	 * sourced from {@see RelatedEntityTypeProvider} to stay consistent with the runtime node-filter resolver.
	 */
	private static function appendRelatedEntityTypeOptions(
		array $entityTypeOptions,
		array $allEntityTypeOptions,
		array $contextDocumentType,
	): array
	{
		$orderedTypeIds = array_merge(
			[RelatedEntityTypeProvider::resolveContextEntityTypeId($contextDocumentType)],
			RelatedEntityTypeProvider::getRelatedEntityTypeIds($contextDocumentType),
		);

		foreach ($orderedTypeIds as $entityTypeId)
		{
			if (
				$entityTypeId > 0
				&& !isset($entityTypeOptions[$entityTypeId])
				&& isset($allEntityTypeOptions[$entityTypeId])
			)
			{
				$entityTypeOptions[$entityTypeId] = $allEntityTypeOptions[$entityTypeId];
			}
		}

		return $entityTypeOptions;
	}
}
