<?php

use Bitrix\Bizproc\Activity\PropertiesDialog;
use Bitrix\Bizproc\Activity\Mixins\ChecksResolvedTargetAccessTrait;
use Bitrix\Bizproc\Activity\Mixins\TargetDocumentResolverTrait;
use Bitrix\Bizproc\FieldType;
use Bitrix\Bizproc\Result\ResultDto;
use Bitrix\Bizproc\Workflow\Template\Converter\NodesToTemplate;
use Bitrix\Crm;
use Bitrix\Crm\Integration\Analytics\Dictionary;
use Bitrix\Crm\Integration\BizProc\NodeFilter\DynamicTypeScope;
use Bitrix\Crm\Integration\BizProc\NodeFilter\DynamicTypeScopePolicy;
use Bitrix\Crm\Integration\BizProc\NodeFilter\TargetDocumentAccessGuard;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

/**
 * @property-write int DynamicTypeId
 * @property-write array DynamicEntitiesFields
 * @property-write bool OnlyDynamicEntities
 * @property-write string DynamicTypeScope
 * @property-write array|string|null RelationSourceDocument
 * @property-write int|null ItemId
 * @property-write string|null ItemIdentifier
 * @property-write string|null ErrorMessage
 */
class CBPCrmCreateDynamicActivity extends \Bitrix\Bizproc\Activity\BaseActivity implements IBPConfigurableActivity
{
	use TargetDocumentResolverTrait;
	use ChecksResolvedTargetAccessTrait;

	protected static $requiredModules = ['crm'];

	public function __construct($name)
	{
		parent::__construct($name);
		$this->arProperties = [
			'Title' => '',
			'DynamicTypeId' => 0,
			'DynamicEntitiesFields' => [],
			'OnlyDynamicEntities' => 'N',
			'DynamicTypeScope' => '',
			'RelationSourceDocument' => null,

			// return
			'ItemId' => null,
			'ItemIdentifier' => null,
			'ErrorMessage' => null,
		];

		$this->SetPropertiesTypes([
			'DynamicTypeId' => ['Type' => FieldType::INT],
			'ErrorMessage' => ['Type' => FieldType::STRING],
		]);
	}

	protected function reInitialize()
	{
		parent::reInitialize();
		$this->ItemId = 0;
		$this->ItemIdentifier = null;
		$this->ErrorMessage = null;
	}

	protected function prepareProperties(): void
	{
		$this->resolveTargetDocumentId();

		parent::prepareProperties();

		$entityFieldsValues = [];
		$fieldIdPrefixLength = mb_strlen($this->DynamicTypeId . '_');
		foreach ($this->DynamicEntitiesFields as $fieldId => $fieldValue)
		{
			$realFieldId = mb_substr($fieldId, $fieldIdPrefixLength);
			$entityFieldsValues[$realFieldId] = $fieldValue;
		}
		$this->preparedProperties['DynamicEntitiesFields'] = $entityFieldsValues;

		if ($this->workflow->isDebug())
		{
			$this->writeDebugInfo($this->getDebugInfo());
		}

		Crm\Service\Container::getInstance()
			->getContext()
			->getAnalytics()
			->setCategory(Dictionary::CATEGORY_ROBOT_OPERATIONS)
			->setType(Dictionary::TYPE_CREATE_DYNAMIC_ACTIVITY)
		;
	}

	protected function checkProperties(): \Bitrix\Main\ErrorCollection
	{
		$errors = parent::checkProperties();

		$documentType = CCrmBizProcHelper::ResolveDocumentType($this->DynamicTypeId);
		if (!isset($documentType))
		{
			$errors->setError(new Error(Loc::getMessage('CRM_CDA_DYNAMIC_TYPE_ID_ERROR')));
		}

		$scope = $this->resolveParentDynamicTypeScope();
		if (
			$scope !== null
			&& !$this->createDynamicTypeScopePolicy()->contains($scope, (int)$this->DynamicTypeId)
		)
		{
			$errors->setError(new Error(
				Loc::getMessage('CRM_CDA_DYNAMIC_TYPE_SCOPE_ERROR'),
				'CRM_CDA_DYNAMIC_TYPE_SCOPE_ERROR',
			));
		}

		return $errors;
	}

	protected function internalExecute(): \Bitrix\Main\ErrorCollection
	{
		$errorCollection = parent::internalExecute();

		$fieldsValues = $this->externalizeDocumentFields();
		$fieldsValues = $this->applyResponsibleFallbackIfNeeded($fieldsValues);
		$this->logDocumentFields($fieldsValues);

		$documentType = $this->getCreatedDocumentType();
		try
		{
			$creationResult = static::getDocumentService()->CreateDocument($documentType, $fieldsValues);
			if (is_int($creationResult))
			{
				$this->bindCreatedItem($creationResult);
			}
		}
		catch (\Bitrix\Main\NotImplementedException $exception)
		{
			$creationResult = false;
		}

		if (is_string($creationResult))
		{
			$errorCollection->setError(new Error($creationResult));
			$this->ErrorMessage = $creationResult;
		}
		elseif ($creationResult === false)
		{
			$errorCollection->setError(new Error(Loc::getMessage('CRM_CDA_ITEM_CREATION_ERROR')));
			$this->ErrorMessage = Loc::getMessage('CRM_CDA_ITEM_CREATION_ERROR');
		}
		elseif (is_int($creationResult))
		{
			$this->ItemId = $creationResult;
			$this->ItemIdentifier = $this->makeCrmItemIdentifierValue($creationResult);
			$this->preparedProperties['ItemId'] = $creationResult;
			$this->preparedProperties['ItemIdentifier'] = $this->ItemIdentifier;
		}

		if ($this->ItemId)
		{
			$this->fixResult($this->makeResultFromId($this->ItemId));
		}

		return $errorCollection;
	}

	protected function getCreatedDocumentType(): array
	{
		return CCrmBizProcHelper::ResolveDocumentType($this->DynamicTypeId);
	}

	public function makeResultFromId($id): ResultDto
	{
		$documentType = $this->getCreatedDocumentType();
		$documentId = $documentType;
		$documentId[2] = $id;

		/** @var CBPDocumentService $documentService */
		$documentService = CBPRuntime::GetRuntime()->GetService('DocumentService');
		$documentId = $documentService->normalizeDocumentId($documentId, $documentType[2]);

		$resultValue = [
			'DOCUMENT_ID' => $documentId,
			'DOCUMENT_TYPE' => $documentType,
			'ITEM_IDENTIFIER' => $this->makeCrmItemIdentifierValue((int)$id),
		];

		return new ResultDto(get_class($this), $resultValue);
	}

	private function makeCrmItemIdentifierValue(int $id): string
	{
		return Crm\UserField\Types\ElementType::getValueByIdentifier(
			new Crm\ItemIdentifier((int)$this->DynamicTypeId, $id),
		);
	}

	private function externalizeDocumentFields(): array
	{
		$documentType = CCrmBizProcHelper::ResolveDocumentType($this->DynamicTypeId);

		$documentService = static::getDocumentService();
		$documentFields = $documentService->getDocumentFields($documentType);
		$documentFieldsAliasesMap = CBPDocument::getDocumentFieldsAliasesMap($documentFields);

		$resultFields = [];
		foreach ($this->DynamicEntitiesFields as $key => $value)
		{
			if (!isset($documentFields[$key]) && isset($documentFieldsAliasesMap[$key]))
			{
				$key = $documentFieldsAliasesMap[$key];
			}

			$property = $documentFields[$key] ?? null;

			if ($property && !CBPHelper::isEmptyValue($value))
			{
				$fieldTypeObject = $documentService->getFieldTypeObject($documentType, $property);
				if ($fieldTypeObject)
				{
					$fieldTypeObject->setDocumentId($this->resolveTargetDocumentId());
					$fieldTypeObject->setValue($value);
					$value = $fieldTypeObject->externalizeValue(
						\Bitrix\Bizproc\FieldType::VALUE_CONTEXT_DOCUMENT,
						$fieldTypeObject->getValue()
					);
				}

				if (isset($value))
				{
					$resultFields[$key] = $value;
				}
			}
		}

		return $resultFields;
	}

	protected function applyResponsibleFallbackIfNeeded(array $fieldsValues): array
	{
		$responsibleFieldName = Crm\Item::FIELD_NAME_ASSIGNED;
		if (!\CBPHelper::isEmptyValue($fieldsValues[$responsibleFieldName] ?? null))
		{
			return $fieldsValues;
		}

		$resolvedTargetDocumentId = $this->resolveTargetDocumentId();
		if (!$this->canReadResolvedTarget($resolvedTargetDocumentId))
		{
			return $fieldsValues;
		}

		return static::applyResponsibleFallback(
			$fieldsValues,
			$this->getSourceDocumentResponsibleId($resolvedTargetDocumentId),
		);
	}

	private static function applyResponsibleFallback(array $fieldsValues, int $responsibleId): array
	{
		$responsibleFieldName = Crm\Item::FIELD_NAME_ASSIGNED;
		if (
			$responsibleId > 0
			&& \CBPHelper::isEmptyValue($fieldsValues[$responsibleFieldName] ?? null)
		)
		{
			$fieldsValues[$responsibleFieldName] = 'user_' . $responsibleId;
		}

		return $fieldsValues;
	}

	protected function getSourceDocumentResponsibleId(array $documentId): int
	{
		return CCrmBizProcHelper::getDocumentResponsibleId($documentId);
	}

	private function logDocumentFields(array $fields)
	{
		if ($this->workflow->isDebug())
		{
			$this->writeDebugInfo(
				$this->getDebugInfo(
					$fields,
					array_intersect_key(static::getEntityFields($this->DynamicTypeId), $fields),
				)
			);
		}
	}

	/**
	 * The item is created by now, so a source that names no CRM element is a skipped binding and a line in
	 * the tracking log, not a failure of the action.
	 */
	private function bindCreatedItem(int $createdItemId): void
	{
		if (!CBPHelper::getBool($this->DynamicEntitiesFields['BindToCurrentElement'] ?? false))
		{
			return;
		}

		$parentDocumentId = $this->resolveBindingParentDocumentId();
		if ($parentDocumentId === null)
		{
			$this->logError(Loc::getMessage('CRM_CDA_BINDING_SOURCE_EMPTY'));

			return;
		}

		if (!$this->canUpdateResolvedTarget($parentDocumentId))
		{
			$this->logError($this->getResolvedTargetAccessDeniedMessage());

			return;
		}

		[$parentEntityTypeId, $parentEntityId] = CCrmBizProcHelper::resolveEntityId($parentDocumentId);
		$parent = Crm\ItemIdentifier::createByParams($parentEntityTypeId, $parentEntityId);
		if ($parent === null)
		{
			$this->logError(Loc::getMessage('CRM_CDA_BINDING_SOURCE_EMPTY'));

			return;
		}

		$this->bindElements($parent, new Crm\ItemIdentifier($this->DynamicTypeId, $createdItemId));
	}

	/** Uses the workflow document only for legacy activities without a relation source. */
	private function resolveBindingParentDocumentId(): ?array
	{
		$documentId = $this->RelationSourceDocument;
		if ($documentId === null && $this->resolveParentDynamicTypeScope() === null)
		{
			$documentId = $this->resolveTargetDocumentId();
		}

		if (is_array($documentId))
		{
			$documentId = array_values($documentId);
			if (($documentId[0] ?? null) !== 'crm' || !is_string($documentId[2] ?? null) || $documentId[2] === '')
			{
				return null;
			}

			return $documentId;
		}

		if (!is_string($documentId) || $documentId === '')
		{
			return null;
		}

		[$entityTypeId, $entityId] = CCrmBizProcHelper::resolveEntityIdByDocumentId($documentId);

		$documentId = CCrmBizProcHelper::ResolveDocumentId($entityTypeId, $entityId);

		return is_array($documentId) ? array_values($documentId) : null;
	}

	protected function isResolvedTargetAccessCheckEnabled(): bool
	{
		return true;
	}

	protected function canUpdateResolvedTarget(array $documentId): bool
	{
		if ($this->getRootStartedByUserId() <= 0)
		{
			return true;
		}

		$rootDocumentId = $this->getRootActivity()->getDocumentId();

		return (new TargetDocumentAccessGuard())->canUpdate(
			is_array($rootDocumentId) ? $rootDocumentId : [],
			$documentId,
			$this->getResolvedTargetAccessActorId(),
		) !== false;
	}

	protected function bindElements(Crm\ItemIdentifier $parent, Crm\ItemIdentifier $child): void
	{
		$itemRelations = new Crm\Automation\Connectors\ItemRelations($child);
		if ($itemRelations->hasParentElement())
		{
			return;
		}
		$bindResult = $itemRelations->bindParentElement($parent);

		if (!$bindResult->isSuccess())
		{
			foreach ($bindResult->getErrorMessages() as $message)
			{
				$this->logError($message);
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
		unset($simpleMap['DynamicEntitiesFields']);
		$result = parent::extractPropertiesValues($dialog, $simpleMap);

		if ($result->isSuccess())
		{
			$currentValues = $result->getData();
			$entityTypeId = (int)$currentValues['DynamicTypeId'];

			$originalDocType = $dialog->getDocumentType();
			$entityDocumentType = \CCrmBizProcHelper::ResolveDocumentType($entityTypeId);

			if ($entityDocumentType)
			{
				$dialog->setDocumentType($entityDocumentType);
			}

			$extractingFieldsResult = parent::extractPropertiesValues(
				$dialog,
				$fieldsMap['DynamicEntitiesFields']['Map'][$entityTypeId] ?? []
			);

			$dialog->setDocumentType($originalDocType);

			if ($extractingFieldsResult->isSuccess())
			{
				$currentValues['DynamicEntitiesFields'] = $extractingFieldsResult->getData();

				$result->setData($currentValues);
			}
			else
			{
				$result->addErrors($extractingFieldsResult->getErrors());
			}
		}

		return $result;
	}

	public static function getPropertiesDialogMap(?PropertiesDialog $dialog = null): array
	{
		$typesMap = Crm\Service\Container::getInstance()->getTypesMap();

		$currentModuleId = null;
		$currentEntityTypeId = null;

		if (isset($dialog))
		{
			$currentModuleId = $dialog->getDocumentType()[0];
			$currentEntityTypeId = CCrmOwnerType::ResolveID($dialog->getDocumentType()[2]) ?: null;
		}

		$typeNames = [];
		$entitiesFields = [];
		foreach ($typesMap->getFactories() as $factory)
		{
			$entityTypeId = $factory->getEntityTypeId();
			$documentType = CCrmBizProcHelper::ResolveDocumentType($entityTypeId);

			if (isset($documentType) && static::isTypeSupported($entityTypeId))
			{
				$typeNames[$entityTypeId] = static::getDocumentService()->getDocumentTypeName($documentType);
				$typeRelations = new Crm\Automation\Connectors\TypeRelations($factory);

				foreach (static::getEntityFieldsWithPrefix($entityTypeId) as $fieldId => $field)
				{
					$parentFieldName = $typeRelations->getParentFieldName($currentEntityTypeId ?? 0);
					$parentFieldName = "{$entityTypeId}_{$parentFieldName}";

					if (
						$currentModuleId === 'crm'
						&& $fieldId === $parentFieldName
						&& $typeRelations->isParentType($currentEntityTypeId)
					)
					{
						$entitiesFields[$entityTypeId]["{$entityTypeId}_BindToCurrentElement"] = [
							'Name' => Loc::getMessage('CRM_CDA_ITEM_FIELD_BIND_TO_CURRENT_ELEMENT'),
							'FieldName' => "{$entityTypeId}_bind_to_current_element",
							'Type' => FieldType::BOOL,
							'Default' => 'Y',
						];
					}

					$entitiesFields[$entityTypeId][$fieldId] = $field;
				}
			}
		}

		$showOnlyDynamicEntities = static::showOnlyDynamicEntities($dialog);
		$typeOptions = $showOnlyDynamicEntities ? static::getOnlyDynamicEntities($typeNames) : $typeNames;
		$scope = static::resolveDynamicTypeScope($dialog);
		if ($scope !== null)
		{
			$typeOptions = static::createDynamicTypeScopePolicy()->filterEntityTypeOptions($scope, $typeOptions);
		}

		return [
			'DynamicTypeId' => [
				'Name' => Loc::getMessage('CRM_CDA_TYPE_ID'),
				'FieldName' => 'dynamic_type_id',
				'Type' => FieldType::SELECT,
				'Options' => $typeOptions,
				'Required' => true,
			],
			'DynamicEntitiesFields' => [
				'FieldName' => 'dynamic_entities_fields',
				'Map' => $entitiesFields,
				'Getter' => function($dialog, $property, $currentActivity, $compatible) {
					return $currentActivity['Properties']['DynamicEntitiesFields'];
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
			'DynamicTypeScope' => [
				'FieldName' => 'dynamic_type_scope',
				'Type' => FieldType::STRING,
				'Default' => $showOnlyDynamicEntities ? $scope?->value ?? '' : '',
				'Settings' => [
					'Hidden' => true,
				],
			],
		];
	}

	private static function getFieldIdWithoutPrefix(int $entityTypeId, string $fieldId): string
	{
		$fieldIdPrefixLength = mb_strlen($entityTypeId . '_');

		return mb_substr($fieldId, $fieldIdPrefixLength);
	}

	/**
	 * The unified form shows the fields of the selected type flat, next to the type itself, so the map is
	 * built for that one type. Values return into DynamicEntitiesFields through the Setter of every field
	 * and are read back from there through its Getter: the storage format of the activity stays as it was,
	 * prefix of the type included, while no field of the form owns a property of its own to be read from.
	 */
	protected static function getPropertiesMap(array $documentType, array $context = []): array
	{
		$activityProperties = is_array($context['Properties'] ?? null) ? $context['Properties'] : [];
		$scope = DynamicTypeScope::tryFrom(
			(string)static::readSelectedValue($activityProperties, 'DynamicTypeScope', 'dynamic_type_scope'),
		);

		$map = static::getBasePropertiesMap($scope);
		$map['RelationSourceDocument'] = static::getRelationSourceDocumentProperty();

		$entityTypeId = (int)static::readSelectedValue($activityProperties, 'DynamicTypeId', 'dynamic_type_id');
		if (
			$entityTypeId <= 0
			|| ($scope !== null && !static::createDynamicTypeScopePolicy()->contains($scope, $entityTypeId))
		)
		{
			return $map;
		}

		$entityFieldsValues = [];
		$createSetter = static function(string $fieldId) use (&$entityFieldsValues): Closure {
			return static function($value) use ($fieldId, &$entityFieldsValues): array {
				$entityFieldsValues[$fieldId] = $value;

				return ['DynamicEntitiesFields' => $entityFieldsValues];
			};
		};
		$createGetter = static function(string $fieldId): Closure {
			return static function($dialog, $property, $currentActivity, $compatible) use ($fieldId) {
				$storedValues = $currentActivity['Properties']['DynamicEntitiesFields'] ?? [];

				return is_array($storedValues) && array_key_exists($fieldId, $storedValues)
					? $storedValues[$fieldId]
					: ($property['Default'] ?? null)
				;
			};
		};

		foreach (static::getEntityFieldsWithPrefix($entityTypeId) as $fieldId => $field)
		{
			// A parent field would be a second channel of binding next to the checkbox below.
			if (Crm\Service\ParentFieldManager::isParentFieldName(
				static::getFieldIdWithoutPrefix($entityTypeId, $fieldId),
			))
			{
				continue;
			}

			$field['Getter'] = $createGetter($fieldId);
			$field['Setter'] = $createSetter($fieldId);
			$map[$fieldId] = $field;
		}

		$hasExactContext = array_key_exists('RelationAncestorBlockIds', $context);
		$exactSource = $hasExactContext ? static::resolveExactSource($entityTypeId, $context) : null;
		$sourceEntityTypeIds = $hasExactContext
			? ($exactSource['entityTypeIds'] ?? [])
			: [static::resolveSourceEntityTypeId($documentType, $context)];
		$checkboxFieldName = "{$entityTypeId}_bind_to_current_element";
		$includeCheckbox = $sourceEntityTypeIds !== [];
		foreach ($sourceEntityTypeIds as $sourceEntityTypeId)
		{
			if (!static::isBoundToSource($entityTypeId, $sourceEntityTypeId))
			{
				$includeCheckbox = false;

				break;
			}
		}
		$storedEntityFields = $activityProperties['DynamicEntitiesFields'] ?? null;
		$hasStoredCheckbox = is_array($storedEntityFields)
			&& array_key_exists("{$entityTypeId}_BindToCurrentElement", $storedEntityFields)
		;
		if (
			!$hasExactContext
			&& (array_key_exists($checkboxFieldName, $activityProperties) || $hasStoredCheckbox)
		)
		{
			$includeCheckbox = true;
		}

		if ($includeCheckbox)
		{
			if ($exactSource !== null)
			{
				$map['RelationSourceDocument'] = static::getRelationSourceDocumentProperty($exactSource['document']);
			}

			$fieldId = "{$entityTypeId}_BindToCurrentElement";
			$map[$fieldId] = [
				'Name' => Loc::getMessage('CRM_CDA_ITEM_FIELD_BIND_TO_CURRENT_ELEMENT'),
				'FieldName' => $checkboxFieldName,
				'Type' => FieldType::BOOL,
				'Default' => 'Y',
				'Getter' => $createGetter($fieldId),
				'Setter' => $createSetter($fieldId),
			];
		}

		return $map;
	}

	private static function getBasePropertiesMap(?DynamicTypeScope $scope): array
	{
		return [
			'DynamicTypeId' => [
				'Name' => Loc::getMessage('CRM_CDA_TYPE_ID'),
				'FieldName' => 'dynamic_type_id',
				'Type' => FieldType::SELECT,
				'Options' => static::getTypeOptions($scope),
				'Required' => true,
			],
			'DynamicTypeScope' => [
				'FieldName' => 'dynamic_type_scope',
				'Type' => FieldType::STRING,
				'Default' => $scope?->value ?? '',
				'Hidden' => true,
				'AllowSelection' => false,
				'Settings' => [
					'Hidden' => true,
				],
			],
		];
	}

	private static function getTypeOptions(?DynamicTypeScope $scope): array
	{
		$typeOptions = static::getSupportedTypeNames();
		if ($scope !== null)
		{
			$typeOptions = static::getOnlyDynamicEntities($typeOptions);
			$typeOptions = static::createDynamicTypeScopePolicy()->filterEntityTypeOptions($scope, $typeOptions);
		}

		return $typeOptions;
	}

	private static function getSupportedTypeNames(): array
	{
		$typeNames = [];
		foreach (static::getSupportedEntityTypeIds() as $entityTypeId)
		{
			$documentType = CCrmBizProcHelper::ResolveDocumentType($entityTypeId);
			if (isset($documentType) && static::isTypeSupported($entityTypeId))
			{
				$typeNames[$entityTypeId] = static::getDocumentService()->getDocumentTypeName($documentType);
			}
		}

		return $typeNames;
	}

	private static function getSupportedEntityTypeIds(): array
	{
		$entityTypeIds = [];
		foreach (CCrmOwnerType::GetAll() as $entityTypeId)
		{
			$entityTypeIds[$entityTypeId] = $entityTypeId;
		}

		$dynamicTypesMap = Crm\Service\Container::getInstance()->getDynamicTypesMap()->load([
			'isLoadStages' => false,
			'isLoadCategories' => false,
		]);
		foreach ($dynamicTypesMap->getTypes() as $type)
		{
			$entityTypeId = $type->getEntityTypeId();
			$entityTypeIds[$entityTypeId] = $entityTypeId;
		}

		return array_values($entityTypeIds);
	}

	/**
	 * The map is asked for the same node twice, and the two callers key their values differently: the form
	 * is built from the properties of the activity, while the save of the node hands the raw values of the
	 * form, named by control. Both name the same choice, so the map answers to either name - otherwise the
	 * save builds a map without the fields of the selected type and drops everything the user filled in.
	 */
	private static function readSelectedValue(array $values, string $propertyName, string $fieldName): mixed
	{
		return $values[$propertyName] ?? $values[$fieldName] ?? '';
	}

	/**
	 * Service field of the binding: the relation action of the node writes the identifier of its source
	 * document here. The control is hidden, not absent - the form needs it to accept that write.
	 */
	private static function getRelationSourceDocumentProperty(?string $sourceDocument = null): array
	{
		$property = [
			'Name' => Loc::getMessage('CRM_CDA_RELATION_SOURCE_DOCUMENT'),
			'FieldName' => 'relation_source_document',
			'Type' => FieldType::DOCUMENT,
			'Multiple' => false,
			'Required' => false,
			'Hidden' => true,
			'Default' => null,
		];
		if ($sourceDocument !== null)
		{
			$property['Getter'] = static function($dialog, $property, $currentActivity, $compatible) use ($sourceDocument) {
				$value = $currentActivity['Properties']['RelationSourceDocument'] ?? null;

				return $value === null || $value === '' || $value === [] ? $sourceDocument : $value;
			};
		}

		return $property;
	}

	private static function isBoundToSource(int $entityTypeId, int $sourceEntityTypeId): bool
	{
		if ($sourceEntityTypeId <= 0)
		{
			return false;
		}

		$factory = Crm\Service\Container::getInstance()->getFactory($entityTypeId);

		return $factory !== null
			&& (new Crm\Automation\Connectors\TypeRelations($factory))->isParentType($sourceEntityTypeId)
		;
	}

	private static function resolveExactSource(int $entityTypeId, array $context): ?array
	{
		$allowedNames = [];
		if (is_array($context['RelationAncestorBlockIds']))
		{
			foreach ($context['RelationAncestorBlockIds'] as $name)
			{
				if (is_string($name) && $name !== '')
				{
					$allowedNames[$name] = true;
				}
			}
		}

		$targetDocumentType = CCrmBizProcHelper::ResolveDocumentType($entityTypeId);
		if ($allowedNames === [] || !is_array($targetDocumentType))
		{
			return null;
		}

		$matches = [];
		static::collectExactSourceMatches(
			$context['Template'] ?? null,
			$allowedNames,
			array_values($targetDocumentType),
			$matches,
		);
		if (count($matches) !== 1)
		{
			return null;
		}

		return [
			'document' => array_key_first($matches),
			'entityTypeIds' => reset($matches),
		];
	}

	private static function collectExactSourceMatches(
		mixed $level,
		array $allowedNames,
		array $targetDocumentType,
		array &$matches,
	): void
	{
		if (!is_array($level))
		{
			return;
		}

		foreach ($level as $node)
		{
			if (!is_array($node))
			{
				continue;
			}

			$name = $node['Name'] ?? null;
			if (is_string($name) && isset($allowedNames[$name]))
			{
				foreach (static::resolveExactSourceMatches($node, $targetDocumentType) as $match)
				{
					$matches['{=' . $name . ':' . $match['targetPropertyId'] . '}'] = $match['sourceEntityTypeIds'];
				}
			}

			static::collectExactSourceMatches(
				$node['Children'] ?? null,
				$allowedNames,
				$targetDocumentType,
				$matches,
			);
		}
	}

	private static function resolveExactSourceMatches(array $node, array $targetDocumentType): array
	{
		$properties = is_array($node['Properties'] ?? null) ? $node['Properties'] : [];
		$publishedProperties = is_array($properties['RelationReturnPropertiesMap'] ?? null)
			? $properties['RelationReturnPropertiesMap']
			: [];
		$groups = [];
		foreach ($publishedProperties as $propertyId => $property)
		{
			if (!is_array($property))
			{
				continue;
			}

			$sourceActivity = $property['SourceActivity'] ?? null;
			if (!is_string($sourceActivity) || $sourceActivity === '')
			{
				continue;
			}

			$groups[$sourceActivity] ??= [
				'sourceEntityTypeSets' => [],
				'targetPropertyIds' => [],
			];
			$package = $property['Settings']['relationPackage'] ?? null;
			if (!is_array($package))
			{
				continue;
			}

			if (($package['role'] ?? null) === 'sourceDocument')
			{
				$groups[$sourceActivity]['sourceEntityTypeSets'][] = static::resolvePackageSourceEntityTypeIds(
					$property,
				);

				continue;
			}

			if (
				($package['role'] ?? null) !== 'targetField'
				|| !is_string($propertyId)
				|| $propertyId === ''
				|| ($package['fieldId'] ?? null) !== 'relation_source_document'
				|| !is_array($package['targetDocumentType'] ?? null)
				|| array_values($package['targetDocumentType']) !== $targetDocumentType
			)
			{
				continue;
			}

			$groups[$sourceActivity]['targetPropertyIds'][] = $propertyId;
		}

		$matches = [];
		foreach ($groups as $group)
		{
			if (
				count($group['sourceEntityTypeSets']) !== 1
				|| $group['sourceEntityTypeSets'][0] === []
				|| count($group['targetPropertyIds']) !== 1
			)
			{
				continue;
			}

			$matches[] = [
				'sourceEntityTypeIds' => $group['sourceEntityTypeSets'][0],
				'targetPropertyId' => $group['targetPropertyIds'][0],
			];
		}

		return $matches;
	}

	private static function resolvePackageSourceEntityTypeIds(array $property): array
	{
		$defaultEntityTypeId = static::resolveExactCrmEntityTypeId($property['Default'] ?? null);
		$package = $property['Settings']['relationPackage'];
		if (!array_key_exists('sourceDocumentTypes', $package))
		{
			return $defaultEntityTypeId > 0 ? [$defaultEntityTypeId] : [];
		}

		if (!is_array($package['sourceDocumentTypes']) || $package['sourceDocumentTypes'] === [])
		{
			return [];
		}

		$entityTypeIds = [];
		foreach ($package['sourceDocumentTypes'] as $documentType)
		{
			$entityTypeId = static::resolveExactCrmEntityTypeId($documentType);
			if ($entityTypeId <= 0)
			{
				return [];
			}

			$entityTypeIds[$entityTypeId] = $entityTypeId;
		}

		return isset($entityTypeIds[$defaultEntityTypeId]) ? array_values($entityTypeIds) : [];
	}

	private static function resolveExactCrmEntityTypeId(mixed $documentType): int
	{
		if (!is_array($documentType))
		{
			return 0;
		}

		$documentType = array_values($documentType);
		if (
			count($documentType) !== 3
			|| ($documentType[0] ?? null) !== 'crm'
			|| !is_string($documentType[1] ?? null)
			|| !is_string($documentType[2] ?? null)
		)
		{
			return 0;
		}

		$entityTypeId = (int)CCrmOwnerType::ResolveID($documentType[2]);
		$resolvedDocumentType = CCrmBizProcHelper::ResolveDocumentType($entityTypeId);

		return is_array($resolvedDocumentType) && array_values($resolvedDocumentType) === $documentType
			? $entityTypeId
			: 0;
	}

	/**
	 * Actual source of the node: the first filter of the host node, and the type of the document of the
	 * template when the slice carries no filter. The fallback keeps legacy callers without a slice working.
	 */
	private static function resolveSourceEntityTypeId(array $documentType, array $context): int
	{
		$hostNode = static::findHostNode($context['Template'] ?? null);
		foreach ($hostNode['Properties']['FilterSettings'] ?? [] as $filter)
		{
			if (!is_array($filter))
			{
				continue;
			}

			// Both keys are accepted by the node filter resolver, so both name a filter type here.
			$entityTypeId = (int)($filter['targetEntityTypeId'] ?? $filter['entityTypeId'] ?? 0);
			if ($entityTypeId > 0)
			{
				return $entityTypeId;
			}
		}

		return (int)CCrmOwnerType::ResolveID($documentType[2] ?? '');
	}

	/**
	 * The editor puts the node being edited first on the canvas it wraps into a root activity.
	 */
	private static function findHostNode(mixed $level): ?array
	{
		if (!is_array($level))
		{
			return null;
		}

		foreach ($level as $node)
		{
			if (!is_array($node))
			{
				continue;
			}

			if (($node['Type'] ?? '') !== NodesToTemplate::ROOT_NODE_TYPE)
			{
				return $node;
			}

			$hostNode = static::findHostNode($node['Children'] ?? null);
			if ($hostNode !== null)
			{
				return $hostNode;
			}
		}

		return null;
	}

	private static function isTypeSupported(int $entityTypeId): bool
	{
		return (bool)CCrmBizProcHelper::ResolveDocumentName($entityTypeId);
	}

	private static function getEntityFieldsWithPrefix(int $entityTypeId): array
	{
		$entityFields = [];
		foreach (static::getEntityFields($entityTypeId) as $fieldId => $field)
		{
			$field['FieldName'] = "{$entityTypeId}_{$field['FieldName']}";
			$entityFields["{$entityTypeId}_{$fieldId}"] = $field;
		}

		return $entityFields;
	}

	private static function getEntityFields(int $entityTypeId): array
	{
		$documentType = CCrmBizProcHelper::ResolveDocumentType($entityTypeId);
		$entityFields = [];

		foreach (static::getDocumentService()->GetDocumentFields($documentType) as $fieldId => $field)
		{
			$isIgnoredField = (
				!isset($field['Editable'])
				|| !$field['Editable']
				|| static::isInternalField($fieldId)
				|| static::isMultiField($field)
			);

			if (!$isIgnoredField || static::isRequiredFieldId($fieldId))
			{
				$entityFields[$fieldId] = $field;
				$entityFields[$fieldId]['FieldName'] = mb_strtolower($fieldId);
				$entityFields[$fieldId]['Type'] = $field['Type'] !== 'UF:date' ? $field['Type'] : 'date';
			}
		}

		return $entityFields;
	}

	private static function isMultiField(array $field): bool
	{
		$fieldTypes = ['phone', 'email', 'web', 'im'];

		return in_array($field['Type'] ?? '', $fieldTypes, true);
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

	protected static function isRequiredFieldId(string $fieldId): bool
	{
		return $fieldId === Crm\Item::FIELD_NAME_CREATED_BY || $fieldId === 'CREATED_BY_ID';
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
			&& ($currentActivity['Properties']['OnlyDynamicEntities'] ?? 'N') === 'Y'
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

	private static function resolveDynamicTypeScope(?PropertiesDialog $dialog): ?DynamicTypeScope
	{
		if ($dialog === null)
		{
			return null;
		}

		$workflowTemplate = $dialog->getWorkflowTemplate();
		$parentActivity = \CBPWorkflowTemplateLoader::FindParentActivityByName(
			$workflowTemplate,
			$dialog->getActivityName(),
		);
		$scope = DynamicTypeScope::fromActivityType(
			is_array($parentActivity) ? (string)($parentActivity['Type'] ?? '') : null,
		);
		if ($scope !== null)
		{
			return $scope;
		}

		$currentActivity = \CBPWorkflowTemplateLoader::FindActivityByName(
			$workflowTemplate,
			$dialog->getActivityName(),
		);
		$scopeValue = is_array($currentActivity)
			? (string)($currentActivity['Properties']['DynamicTypeScope'] ?? '')
			: ''
		;
		if ($scopeValue === '')
		{
			$scopeValue = (string)$dialog->getCurrentValue('dynamic_type_scope', '');
		}

		return DynamicTypeScope::tryFrom($scopeValue);
	}

	private function resolveParentDynamicTypeScope(): ?DynamicTypeScope
	{
		for ($activity = $this->parent; $activity !== null; $activity = $activity->parent)
		{
			if (is_callable([$activity, 'getNodeFilterDynamicTypeScope']))
			{
				$scope = $activity::getNodeFilterDynamicTypeScope();
				if ($scope instanceof DynamicTypeScope)
				{
					return $scope;
				}
			}
		}

		return null;
	}

	protected static function createDynamicTypeScopePolicy(): DynamicTypeScopePolicy
	{
		return DynamicTypeScopePolicy::getRequestInstance();
	}
}
