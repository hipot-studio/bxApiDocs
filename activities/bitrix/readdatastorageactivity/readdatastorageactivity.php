<?php

declare(strict_types=1);

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\Bizproc\Automation\Engine\ConditionGroup;
use Bitrix\Bizproc\Activity\PropertiesDialog;
use Bitrix\Bizproc\Public\Provider\StorageFieldProvider;
use Bitrix\Bizproc\Public\Provider\StorageItemProvider;
use Bitrix\Bizproc\FieldType;
use Bitrix\Bizproc\Public\Provider\StorageTypeProvider;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Bizproc\Activity\BaseActivity;
use Bitrix\Bizproc\Internal\Service\StorageField\FieldService;
use Bitrix\Main\Web\Json;
use Bitrix\Bizproc\Internal\Repository\Mapper\StorageItemMapper;
use Bitrix\Bizproc\BaseType\Value\DateTime;

/**
 * @property-write ?int StorageId
 * @property-write ?string StorageCode
 * @property-write array DynamicFilterFields
 * @property-write array ReturnFields
 * @property-write array ReturnFieldsByStorageCode
 * @property-write int ItemId
 * @property-write string ReturnMode
 * @property-write ?array OutputFields
 * @property-write string IsExpanded
 */
class CBPReadDataStorageActivity extends BaseActivity implements IBPConfigurableActivity
{
	use \Bitrix\Bizproc\Activity\Mixins\EntityFilter;

	private const RETURN_MODE_SINGLE = 'single';
	private const RETURN_MODE_COLLECTION = 'collection';
	private const COLLECTION_LIMIT = 500;

	public function __construct($name)
	{
		parent::__construct($name);
		$this->arProperties = [
			'Title' => '',
			'StorageId' => 0,
			'StorageCode' => '',
			'DynamicFilterFields' => ['items' => []],
			'ReturnFields' => [],
			'ReturnFieldsByStorageCode' => [],
			'ItemId' => 0,
			'ReturnMode' => self::RETURN_MODE_SINGLE,
			'IsExpanded' => 'Y',

			// return
			'OutputFields' => null,
			'CollectionJson' => '',
		];

		$this->setPropertiesTypes([
			'StorageId' => ['Type' => FieldType::INT],
			'StorageCode' => ['Type' => FieldType::STRING],
			'ReturnFieldsByStorageCode' => ['Type' => FieldType::STRING, 'Multiple' => true],
			'ReturnMode' => ['Type' => FieldType::SELECT],
			'CollectionJson' => ['Type' => FieldType::TEXT],
		]);
	}

	protected function prepareProperties(): void
	{
		parent::prepareProperties();

		try
		{
			if (!($this->ItemId > 0))
			{
				$this->preparedProperties['ItemId'] = $this->findStorageItemId();
			}
			else
			{
				$this->preparedProperties['ItemId'] = (int)$this->ItemId;
			}
		}
		catch (\Bitrix\Main\ArgumentException $exception)
		{
			$this->preparedProperties['ItemId'] = 0;
		}
	}

	protected function findStorageItemId(): int
	{
		$storageId = $this->findStorageId();
		if (!$storageId)
		{
			return 0;
		}

		$conditionGroup = new ConditionGroup($this->DynamicFilterFields);
		$provider = new StorageItemProvider($storageId);

		$documentType = \Bitrix\Bizproc\Public\Entity\Document\Workflow::getComplexType();
		$fieldsMap = static::getFilteringFieldsMap($storageId);
		$filter = $this->getOrmFilter($conditionGroup, $documentType, $fieldsMap);
		$item = $provider->getItems([
			'filter' => $filter,
			'select' => ['ID'],
			'order' => ['ID' => 'DESC'],
			'limit' => 1,
		])?->getFirstCollectionItem();

		return $item ? $item->getId() : 0;
	}

	private function findStorageId(): int
	{
		$storageId = (int)$this->StorageId;
		if ($storageId <= 0)
		{
			$rawStorageCode = $this->StorageCode;
			$storageCode = CBPHelper::hasStringRepresentation($rawStorageCode) ? (string)$rawStorageCode : '';

			$provider = new StorageTypeProvider();
			$type = $provider->getType(['CODE' => $storageCode], ['ID']);
			if ($type)
			{
				$storageId = (int)$type->getId();
			}
		}

		return $storageId;
	}

	protected function checkProperties(): \Bitrix\Main\ErrorCollection
	{
		$errors = parent::checkProperties();

		if ($this->ItemId <= 0)
		{
			$errors->setError(new Error(Loc::getMessage('BIZPROC_SRA_ITEM_NOT_FOUND')));
		}

		if (!in_array($this->ReturnMode, [self::RETURN_MODE_SINGLE, self::RETURN_MODE_COLLECTION], true))
		{
			$errors->setError(new Error(Loc::getMessage('BIZPROC_SRA_RETURN_MODE_WRONG')));
		}

		return $errors;
	}

	protected function internalExecute(): \Bitrix\Main\ErrorCollection
	{
		$errors = parent::internalExecute();
		$provider = new StorageItemProvider($this->findStorageId());

		$this->arProperties['CollectionJson'] = '';
		$this->preparedProperties['CollectionJson'] = '';

		if ($this->ReturnMode === self::RETURN_MODE_SINGLE)
		{
			$this->executeSingleMode($provider);
		}
		else
		{
			$this->executeCollectionMode($provider);
		}

		$outputFields = $this->OutputFields;
		if (is_array($outputFields))
		{
			$returnFieldsMap = static::getReturnFieldsMap($this->findStorageId());
			foreach ($outputFields as $fieldKey => &$fieldProperty)
			{
				if (isset($returnFieldsMap[$fieldKey]))
				{
					$fieldName = $fieldProperty['FieldName'];
					$fieldProperty = $returnFieldsMap[$fieldKey];
					$fieldProperty['FieldName'] = $fieldName;
				}
			}
			unset($fieldProperty);

			$this->setPropertiesTypes($outputFields);
		}

		return $errors;
	}

	protected function executeSingleMode(StorageItemProvider $provider): void
	{
		$select = $this->getSelectFields();
		$item = $provider->getById((int)$this->ItemId, $select)?->toArray();
		if (!$item)
		{
			return;
		}

		$offset = \CTimeZone::GetOffset();
		foreach ($this->computeReturnFields() as $key => $fieldId)
		{
			$value = $item[$fieldId] ?? null;
			if ($fieldId === 'createdAt')
			{
				$value = $value ? new DateTime($value, $offset) : null;
			}
			$this->arProperties[$key] = $value;
			$this->preparedProperties[$key] = $value;
		}
	}

	private function getSelectFields(): array
	{
		$select = ['VALUE'];
		$fieldsMap = array_flip(StorageItemMapper::getFieldsMap());

		foreach ($this->computeReturnFields() as $field)
		{
			if (isset($fieldsMap[$field]))
			{
				$select[] = $fieldsMap[$field];
			}
		}

		return $select;
	}

	protected function computeReturnFields(): array
	{
		$testReturnFields = CBPHelper::flatten($this->ReturnFields);
		if ($testReturnFields)
		{
			$returnFields = [];
			foreach ($testReturnFields as $fieldId)
			{
				$returnFields[$fieldId] = $fieldId;
			}

			return $returnFields;
		}

		$rawReturnFields = CBPHelper::flatten($this->getRawProperty('ReturnFieldsByStorageCode'));

		$returnFields = [];
		foreach (CBPHelper::flatten($this->ReturnFieldsByStorageCode) as $key => $value)
		{
			$returnFields[static::sanitizeFieldId($rawReturnFields[$key])] = $value;
		}

		return $returnFields;
	}

	protected function executeCollectionMode(StorageItemProvider $provider): void
	{
		$conditionGroup = new ConditionGroup($this->DynamicFilterFields);
		$documentType = \Bitrix\Bizproc\Public\Entity\Document\Workflow::getComplexType();

		$storageId = $this->findStorageId();
		$fieldsMap = static::getFilteringFieldsMap($storageId);
		$filter = $this->getOrmFilter($conditionGroup, $documentType, $fieldsMap);

		$params = [
			'filter' => $filter,
			'select' => ['*'],
			'limit' => static::COLLECTION_LIMIT,
		];

		$collection = $provider->getItems($params);

		$items = [];

		if ($collection)
		{
			$items = $this->formatItems($collection);
		}

		$json = $this->encodeCollectionResult($items, $storageId);
		$this->arProperties['CollectionJson'] = $json;
		$this->preparedProperties['CollectionJson'] = $json;
		$this->clearReturnFields();
	}

	private function formatItems(\Bitrix\Bizproc\Internal\Entity\StorageItem\StorageItemCollection $collection): array
	{
		$fields = static::getReturnFieldsMap($this->findStorageId());
		$documentService = CBPRuntime::GetRuntime(true)->getDocumentService();
		$documentType = \Bitrix\Bizproc\Public\Entity\Document\Workflow::getComplexType();
		$items = [];

		foreach ($collection as $item)
		{
			$filteredItem = [];
			foreach ($this->computeReturnFields() as $fieldId)
			{
				if (!isset($fields[$fieldId]))
				{
					continue;
				}

				$fieldProperties = $fields[$fieldId];
				$fieldType = $documentService->getFieldTypeObject($documentType, $fieldProperties);
				if (!$fieldType)
				{
					continue;
				}

				$code = $fieldProperties['FieldName'];
				$value = $item->toArray()[$fieldId] ?? null;
				$filteredItem[$code] = $fieldType->formatValue($value);
			}

			$items[] = $filteredItem;
		}

		return $items;
	}

	private function encodeCollectionResult(array $items, int $storageId): string
	{
		$typeProvider = new StorageTypeProvider();
		$storage = $typeProvider->getById($storageId);

		$jsonResult = [
			'metadata' => [
				'source' => $storage?->getTitle(),
				'source_description' => $storage?->getDescription(),
				'total_records' => count($items),
				'fields_description' => $this->getFieldsDescription(),
			],
			'data' => $items,
		];

		return Json::encode($jsonResult, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	}

	protected function getFieldsDescription(): array
	{
		$descriptions = [];
		$fields = static::getReturnFieldsMap($this->findStorageId());
		foreach ($this->computeReturnFields() as $fieldId)
		{
			if (!isset($fields[$fieldId]))
			{
				continue;
			}

			$descriptions[$fieldId] = [
				'name' => $fields[$fieldId]['Name'],
				'description' => $fields[$fieldId]['Description'] ?? '',
				'type' => $fields[$fieldId]['Type'],
			];
		}

		return $descriptions;
	}

	protected function reInitialize()
	{
		parent::reInitialize();

		if (is_array($this->computeReturnFields()))
		{
			$this->clearReturnFields();
		}

		$this->arProperties['CollectionJson'] = '';
		$this->preparedProperties['CollectionJson'] = '';
	}

	protected static function getFileName(): string
	{
		return __FILE__;
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
			'DocumentName' => static::getDocumentService()->getEntityName($documentType[0], $documentType[1]),
			'DocumentFields' => array_values(\Bitrix\Bizproc\Automation\Helper::getDocumentFields($documentType)),
		]);

		return $dialog;
	}

	protected static function extractPropertiesValues(PropertiesDialog $dialog, array $fieldsMap): Result
	{
		$simpleMap = $fieldsMap;
		unset($simpleMap['DynamicFilterFields']);
		$result = parent::extractPropertiesValues($dialog, $simpleMap);

		if ($result->isSuccess())
		{
			$currentValues = $result->getData();
			$storageId = (int)$currentValues['StorageId'];

			$currentValues['DynamicFilterFields'] = static::extractFilterFromProperties($dialog, $fieldsMap)->getData();
			$currentValues['ReturnFields'] = $dialog->getCurrentValue('return_fields', []);
			$currentValues['ReturnMode'] = $dialog->getCurrentValue('return_mode', self::RETURN_MODE_SINGLE);

			if ($storageId > 0)
			{
				$currentValues['StorageCode'] = '';
				$currentValues['ReturnFieldsByStorageCode'] = [];
			}

			if (!CBPHelper::isEmptyValue($currentValues['StorageCode']))
			{
				$currentValues['StorageId'] = null;
				$currentValues['ReturnFields'] = [];
			}

			$outputFields = [];
			if ($currentValues['ReturnMode'] === self::RETURN_MODE_SINGLE)
			{
				$returnFieldsMap = static::getReturnFieldsMap($storageId);
				foreach ($currentValues['ReturnFields'] as $fieldId)
				{
					if (isset($returnFieldsMap[$fieldId]))
					{
						$outputFields[$fieldId] = $returnFieldsMap[$fieldId];
					}
				}

				$returnFieldsByStorageCode = CBPHelper::flatten($currentValues['ReturnFieldsByStorageCode']);
				foreach ($returnFieldsByStorageCode as $fieldId)
				{
					$fieldIdClean = static::sanitizeFieldId($fieldId);
					$outputFields[$fieldIdClean] = [
						'Name' => $fieldId,
						'FieldName' => $fieldIdClean,
						'Type' => FieldType::STRING,
					];
				}
			}

			if ($currentValues['ReturnMode'] === self::RETURN_MODE_COLLECTION)
			{
				$outputFields['CollectionJson'] = [
					'Name' => Loc::getMessage('BIZPROC_SRA_RETURN_JSON_COLLECTION'),
					'FieldName' => 'CollectionJson',
					'Type' => FieldType::TEXT,
					'Required' => false,
					'AllowSelection' => true,
				];
			}

			$currentValues['OutputFields'] = $outputFields;

			$result->setData($currentValues);
		}

		return $result;
	}

	public static function validateProperties($testProperties = [], \CBPWorkflowTemplateUser $user = null)
	{
		$errors = [];

		if (
			CBPHelper::isEmptyValue($testProperties['StorageId'] ?? null)
			&& CBPHelper::isEmptyValue($testProperties['StorageCode'] ?? null)
		)
		{
			$errors[] = [
				'code' => 'NotExist',
				'parameter' => 'FieldValue',
				'message' => Loc::getMessage('BIZPROC_SRA_EMPTY_STORAGE_ID_OR_CODE'),
			];
		}

		if (
			(
				!CBPHelper::isEmptyValue($testProperties['StorageId'] ?? null)
				&& CBPHelper::isEmptyValue($testProperties['ReturnFields'] ?? null)
			)
			|| (
				!CBPHelper::isEmptyValue($testProperties['StorageCode'] ?? null)
				&& CBPHelper::isEmptyValue($testProperties['ReturnFieldsByStorageCode'] ?? null)
			)
		)
		{
			$errors[] = [
				'code' => 'EmptyField',
				'parameter' => 'ReturnFields',
				'message' => Loc::getMessage('BIZPROC_SRA_EMPTY_RETURN_FIELDS'),
			];
		}

		return array_merge($errors, parent::validateProperties($testProperties, $user));
	}

	protected static function getReturnFieldsMap(int $storageId): array
	{
		$fieldsMap = [];

		try
		{
			$provider = new StorageFieldProvider;
			$fieldCollection = $provider->getByStorageId($storageId);

			foreach ($fieldCollection as $field)
			{
				$property = $field->toProperty();
				$fieldsMap[$property['FieldName']] = $property;
			}
		}
		catch (\Bitrix\Main\ArgumentException $exception) {}

		return static::getSystemFields() + $fieldsMap;
	}

	private static function getSystemFields(): array
	{
		$fieldService = new FieldService();
		$fields = $fieldService->getEntityFields();

		$supportedFields = [
			'ID',
			'CODE',
			'WORKFLOW_ID',
			'DOCUMENT_ID',
			'TEMPLATE_ID',
			'CREATED_BY',
			'CREATED_TIME',
		];

		$systemFields = [];
		$fieldsMap = StorageItemMapper::getFieldsMap();
		foreach ($fields as $field)
		{
			if (in_array($field['ID'], $supportedFields, true))
			{
				$systemFields[$fieldsMap[$field['ID']]] = [
					'Name' => $field['NAME'],
					'FieldName' => $fieldsMap[$field['ID']],
					'Type' => $field['TYPE'],
					'Required' => false,
					'AllowSelection' => true,
				];
			}
		}

		return $systemFields;
	}

	public static function getPropertiesDialogMap(?PropertiesDialog $dialog = null): array
	{
		return static::getPropertiesMap([]);
	}

	public static function getPropertiesMap(array $documentType, array $context = []): array
	{
		$dynamicFilterFields = $context['Properties']['DynamicFilterFields'] ?? null;
		$returnFields = $context['Properties']['ReturnFields'] ?? null;
		$returnFieldsByStorageCode = $context['Properties']['return_fields_by_storage_code'] ?? null;

		$filteringFieldsMap = [
			0 => array_values(static::getFilteringFieldsMap(0))
		];
		$returnFieldsMap = [];
		$storages = static::getStorageTypes();

		foreach ($storages as $id => $title)
		{
			$returnFieldsMap[$id] = static::getReturnFieldsMap($id);
			$filteringFieldsMap[$id] = array_values(static::getFilteringFieldsMap($id));
		}

		return [
			'StorageId' => [
				'Name' => Loc::getMessage('BIZPROC_SRA_STORAGE_ID_PROPERTY'),
				'FieldName' => 'storage_id',
				'Type' => FieldType::ENTITYSELECTOR,
				'Settings' => [
					'entity' => ['id' => 'bizproc-storage'],
					'dialogOptions' => [
						'width' => 445,
						'height' => 300,
					],
				],
				'Required' => false,
				'AllowSelection' => false,
			],
			'StorageCode' => [
				'Name' => '',
				'FieldName' => 'storage_code',
				'Description' => Loc::getMessage('BIZPROC_SRA_FIELD_RECORD_CODE_DESCRIPTION'),
				'Type' => FieldType::STRING,
				'Required' => false,
				'AllowSelection' => true,
			],
			'ReturnMode' => [
				'Name' => Loc::getMessage('BIZPROC_SRA_RETURN_MODE_PROPERTY'),
				'FieldName' => 'return_mode',
				'Type' => FieldType::SELECT,
				'Options' => [
					self::RETURN_MODE_SINGLE => Loc::getMessage('BIZPROC_SRA_RETURN_MODE_SINGLE_PROPERTY'),
					self::RETURN_MODE_COLLECTION => Loc::getMessage('BIZPROC_SRA_RETURN_MODE_COLLECTION_PROPERTY'),
				],
				'Required' => true,
				'AllowSelection' => false,
				'Default' => self::RETURN_MODE_SINGLE,
			],
			'DynamicFilterFields' => [
				'Name' => Loc::getMessage('BIZPROC_SRA_FILTER_FIELDS_PROPERTY'),
				'FieldName' => 'filter_fields',
				'Type' => \Bitrix\Bizproc\FieldType::CUSTOM,
				'Required' => false,
				'AllowSelection' => true,
				'CustomType' => 'filterFields',
				'Options' => [
					'documentType' => \Bitrix\Bizproc\Public\Entity\Document\Workflow::getComplexType(),
					'filteringFieldsPrefix' => 'filter_fields_',
					'filterFieldsMap' => $filteringFieldsMap,
					'conditions' => $dynamicFilterFields,
					'collapsedCaption' => Loc::getMessage('BIZPROC_SRA_FILTER_FIELDS_COLLAPSED_TEXT'),
					'returnFieldsIds' => $returnFields,
					'returnFieldsMap' => $returnFieldsMap,
				]
			],
			'IsExpanded' => [
				'Name' => '',
				'FieldName' => 'is_expanded',
				'Type' => FieldType::STRING,
				'Required' => false,
				'AllowSelection' => false,
				'Hidden' => true,
				'Default' => 'Y',
			],
			'ReturnFields' => [
				'Name' => Loc::getMessage('BIZPROC_SRA_RETURN_FIELDS_SELECTION'),
				'FieldName' => 'return_fields',
				'Type' => FieldType::SELECT,
				'Options' => [],
				'Multiple' => true,
				'Required' => false,
				'Map' => $returnFieldsMap,
				'AllowSelection' => false,
			],
			'ReturnFieldsByStorageCode' => [
				'Name' => Loc::getMessage('BIZPROC_SRA_RETURN_FIELDS_SELECTION'),
				'FieldName' => 'return_fields_by_storage_code',
				'Type' => FieldType::STRING,
				'Multiple' => true,
				'Required' => false,
				'AllowSelection' => true,
			],
		];
	}

	protected static function getFilteringFieldsMap($storageId): array
	{
		$supportedFields = [
			'ID',
			'CODE',
			'WORKFLOW_ID',
			'DOCUMENT_ID',
			'TEMPLATE_ID',
			'CREATED_BY',
			'CREATED_TIME',
		];

		$map = [];
		$fieldService = new FieldService($storageId);
		$fields = $fieldService->getEntityFields();

		foreach ($fields as $key => $field)
		{
			if (in_array($field['ID'], $supportedFields, true))
			{
				$type = $field['TYPE'];
				if ($type === 'integer')
				{
					$type = FieldType::INT;
				}

				$map[$field['ID']] = [
					'Id' => $field['ID'],
					'Name' => $field['NAME'],
					'Type' => $type,
					'Expression' => "{{{$field['NAME']}}}",
					'SystemExpression' => "{=Storage:{$field['ID']}}",
					'Options' => null,
					'Settings' => null,
					'Multiple' => false,
				];
			}
		}

		return $map;
	}

	private static function getStorageTypes(): array
	{
		$options = [];

		$provider = new StorageTypeProvider();
		$storages = $provider->getAllForActivity();

		foreach ($storages as $storage)
		{
			$options[$storage->getId()] = $storage->getTitle();
		}

		return $options;
	}

	private function clearReturnFields(): void
	{
		foreach ($this->computeReturnFields() as $key => $fieldId)
		{
			$this->arProperties[$key] = null;
			$this->preparedProperties[$key] = null;
		}
	}

	private static function sanitizeFieldId(string $fieldId): string
	{
		return preg_replace('/\W/', '', $fieldId);
	}
}
