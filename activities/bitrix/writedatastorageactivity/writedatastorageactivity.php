<?php

declare(strict_types=1);

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\Bizproc\Internal\Entity\StorageItem\StorageItem;
use Bitrix\Bizproc\Public\Command\StorageItem\AddStorageItemCommand;
use Bitrix\Bizproc\Public\Command\StorageItem\UpdateStorageItemCommand;
use Bitrix\Bizproc\Public\Provider\StorageFieldProvider;
use Bitrix\Bizproc\Public\Provider\StorageItemProvider;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Bizproc\Automation\Engine\ConditionGroup;
use Bitrix\Bizproc\Activity\BaseActivity;
use Bitrix\Bizproc\Activity\PropertiesDialog;
use Bitrix\Bizproc\FieldType;
use Bitrix\Bizproc\Internal\Service\StorageField\FieldService;
use Bitrix\Bizproc\Internal\Service\StorageActivity\StorageActivityService;

/**
 * @property-write ?int StorageId
 * @property-write ?string StorageCode
 * @property-write ?array FieldValue
 * @property-write ?int Author
 * @property-write ?int ItemId
 * @property-write ?array DynamicFilterFields
 * @property-write ?string RewriteMode
 * @property-write string IsExpanded
 */
class CBPWriteDataStorageActivity extends BaseActivity implements IBPConfigurableActivity
{
	use \Bitrix\Bizproc\Activity\Mixins\EntityFilter;

	private array $complexDocumentId = [];
	private const MODE_NEW_ITEM = 'newItem';
	private const MODE_MERGE_FIELDS = 'mergeFields';
	private const MODE_REWRITE_FIELDS = 'rewriteFields';
	private const FIELD_CONTROL_PREFIX = 'field_values_';
	private const FIELD_CONTROL_SUFFIX = '__bpctl';

	public function __construct($name)
	{
		parent::__construct($name);
		$this->arProperties = [
			'StorageId' => null,
			'StorageCode' => null,
			'FieldValue' => null,
			'Author' => null,
			'ItemId' => null,
			'DynamicFilterFields' => null,
			'RewriteMode' => null,
			'IsExpanded' => 'Y',
		];
	}

	protected static function getFileName(): string
	{
		return __FILE__;
	}

	public function execute()
	{
		$fieldValue = $this->FieldValue;
		$rewriteMode = $this->RewriteMode ?? self::MODE_NEW_ITEM;
		$storageId = (int)$this->StorageId;
		if ($storageId <= 0 && empty($this->StorageCode))
		{
			$this->trackError(Loc::getMessage('BIZPROC_WRITE_DATA_ACTIVITY_WRONG_STORAGE_ID') ?? '');

			return CBPActivityExecutionStatus::Closed;
		}
		if (\CBPHelper::isEmptyValue($fieldValue))
		{
			$this->trackError(Loc::getMessage('BIZPROC_WRITE_DATA_ACTIVITY_WRONG_FIELD_VALUE') ?? '');

			return CBPActivityExecutionStatus::Closed;
		}
		$this->setComplexDocumentId($this->getDocumentId());
		$authorId = CBPHelper::extractFirstUser($this->Author, $this->getComplexDocumentId());
		if ((int)$authorId <= 0)
		{
			$this->trackError(Loc::getMessage('BIZPROC_WRITE_DATA_ACTIVITY_AUTHOR_NOT_FOUND') ?? '');

			return CBPActivityExecutionStatus::Closed;
		}

		$this->findStorageTypeId();
		if ((int)$this->StorageId <= 0)
		{
			return CBPActivityExecutionStatus::Closed;
		}

		$storageFields = self::getStorageFields((int)$this->StorageId);
		$storageFieldMap = array_column($storageFields, null, 'FieldName');
		$fieldsData = $this->filterStorageFields($storageFieldMap, $fieldValue);

		$this->ItemId = $this->findStorageItemId();
		$itemId = (int)$this->ItemId;
		if (($rewriteMode === self::MODE_MERGE_FIELDS || $rewriteMode === self::MODE_REWRITE_FIELDS) && $itemId <= 0)
		{
			$this->trackError(Loc::getMessage('BIZPROC_WRITE_DATA_ACTIVITY_WRONG_ITEM_ID') ?? '');

			return CBPActivityExecutionStatus::Closed;
		}

		$saveResult = match ($rewriteMode)
		{
			self::MODE_NEW_ITEM => $this->createNewStorageItem($fieldsData, $authorId),
			self::MODE_MERGE_FIELDS => $this->updateStorageItem($fieldsData, $authorId, $storageFieldMap),
			self::MODE_REWRITE_FIELDS => $this->updateStorageItem($fieldsData, $authorId, $storageFieldMap, false),
			default => $this->handleUnknownRewriteMode($rewriteMode)
		};
		if (!$saveResult->isSuccess())
		{
			$this->trackError($saveResult->getErrorMessages()[0]);
		}

		return CBPActivityExecutionStatus::Closed;
	}

	private function findStorageTypeId(): void
	{
		$storageId = (int)$this->StorageId;
		if ($storageId <= 0)
		{
			$rawStorageCode = $this->StorageCode;
			$storageCode = CBPHelper::hasStringRepresentation($rawStorageCode) ? (string)$rawStorageCode : '';

			$resolved = StorageActivityService::resolveStorageId(null, $storageCode);
			if ($resolved > 0)
			{
				$this->StorageId = $resolved;
			}
			else
			{
				$this->trackError(Loc::getMessage('BIZPROC_WRITE_DATA_ACTIVITY_STORAGE_NOT_FOUND') ?? '');
			}
		}
	}

	private function createNewStorageItem(array $fieldsData, int $author): Result
	{
		[, , $documentId] = $this->getComplexDocumentId();
		$templateId = $this->getWorkflowTemplateId();
		$workflowId = $this->getWorkflowInstanceId();

		$item = (new StorageItem())
			->setDocumentId($documentId)
			->setWorkflowId($workflowId)
			->setTemplateId($templateId)
			->setValueFields($fieldsData);

		$addItemCommand = new AddStorageItemCommand(
			createdBy: $author,
			storageTypeId: (int)$this->StorageId,
			storageItem: $item
		);

		return $addItemCommand->run();
	}

	private function updateStorageItem(
		array $fieldsData,
		int $authorId,
		array $storageFields,
		bool $mergeMode = true
	): Result
	{
		[, , $documentId] = $this->getComplexDocumentId();
		$templateId = $this->getWorkflowTemplateId();
		$workflowId = $this->getWorkflowInstanceId();
		$storageId = (int)$this->StorageId;
		$existingItem = $this->findStorageItem();

		if (!$existingItem)
		{
			$result = new Result();
			$itemId = (int)$this->ItemId;
			$result->addError(new \Bitrix\Main\Error(
				Loc::getMessage('BIZPROC_WRITE_DATA_ACTIVITY_ITEM_NOT_FOUND', [
					'#ITEM_ID#' => $itemId,
				]) ?: "Item not found for update: {$itemId}"
			));

			return $result;
		}

		$existingItem
			->setDocumentId($documentId)
			->setWorkflowId($workflowId)
			->setTemplateId($templateId)
		;

		if ($mergeMode)
		{
			$currentData = $existingItem->getValueFields();
			$result = $currentData;
			foreach ($currentData as $key => $value)
			{
				if (!array_key_exists($key, $fieldsData))
				{
					continue;
				}

				$newValue = $fieldsData[$key];

				if ($value === null || $value === '')
				{
					// fill empty value with new data
					$result[$key] = $newValue;
				}
				elseif ($storageFields[$key]['Multiple'])
				{
					// Append new elements to the array
					$value = is_array($value) ? $value : [$value];
					$newValue = is_array($newValue) ? $newValue : [$newValue];
					$merged = array_merge($value, $newValue);
					$result[$key] = array_values(array_filter($merged, static fn($v) => $v !== null && $v !== ''));
				}
				//in other cases keep the current data
			}

			$existingItem->setValueFields($result);
		}
		else
		{
			$existingItem->setValueFields($fieldsData);
		}

		$updateItemCommand = new UpdateStorageItemCommand(
			updatedBy: $authorId,
			storageTypeId: $storageId,
			storageItem: $existingItem
		);

		return $updateItemCommand->run();
	}

	private function findStorageItem(): ?StorageItem
	{
		return (new StorageItemProvider((int)$this->StorageId))->getById((int)$this->ItemId);
	}

	private function handleUnknownRewriteMode(string $mode): Result
	{
		$result = new Result();
		$result->addError(new \Bitrix\Main\Error(
			Loc::getMessage('BIZPROC_WRITE_DATA_ACTIVITY_UNKNOWN_MODE', [
				'#MODE#' => $mode
			]) ?: "Unknown rewrite mode: {$mode}"
		));

		return $result;
	}

	private function filterStorageFields(array $storageFieldMap, array $fieldValue): array
	{
		$allowedFieldNames = array_column($storageFieldMap, 'FieldName');
		$allowedFieldsMap = array_flip($allowedFieldNames);
		$mapFieldNameToType = array_column($storageFieldMap, 'Type', 'FieldName');
		$storageFields = array_fill_keys($allowedFieldNames, null);

		foreach ($fieldValue as $field => $value)
		{
			$targetField = $this->findTargetField($field, $allowedFieldsMap);
			if ($targetField)
			{
				$fieldType = $mapFieldNameToType[$targetField] ?? '';
				$storageFields[$targetField] = $this->convertDateValue($value, $fieldType);
			}
		}

		return $storageFields;
	}

	private function findTargetField(string $field, array $allowedFieldsMap): ?string
	{
		if (isset($allowedFieldsMap[$field]))
		{
			return $field;
		}

		if (static::isExpression($field))
		{
			$parsedField = $this->parseValue($field);
			if (isset($allowedFieldsMap[$parsedField]))
			{
				return $parsedField;
			}
		}

		return null;
	}

	private function convertDateValue(mixed $value, string $fieldType): mixed
	{
		if ($fieldType === 'datetime' && $value instanceof \Bitrix\Bizproc\BaseType\Value\Date)
		{
			return new \Bitrix\Bizproc\BaseType\Value\DateTime($value->getTimestamp(), $value->getOffset());
		}

		return $value;
	}

	public static function getPropertiesDialogMap(?PropertiesDialog $dialog = null): array
	{
		$context = [];
		if ($dialog !== null)
		{
			$context = ['Properties' => $dialog->getCurrentValues()];
		}

		return static::getPropertiesMap([], $context);
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
		$fieldService = new FieldService((int)$storageId);
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


	protected function findStorageItemId(): int
	{
		if (!$this->StorageId)
		{
			$this->findStorageTypeId();
		}

		$conditionGroup = new ConditionGroup((array)($this->DynamicFilterFields ?? []));
		$provider = new StorageItemProvider((int)$this->StorageId);

		$documentType = \Bitrix\Bizproc\Public\Entity\Document\Workflow::getComplexType();
		$fieldsMap = StorageActivityService::getFilteringFieldsMap((int)$this->StorageId);
		$filter = $this->getOrmFilter($conditionGroup, $documentType, $fieldsMap);
		if (!$this->isOrmFilterValid() || StorageActivityService::isOrmFilterEmpty($filter))
		{
			return 0;
		}

		$item = $provider->getItems([
			'filter' => $filter,
			'select' => ['ID'],
			'order' => ['ID' => 'DESC'],
			'limit' => 1,
		])?->getFirstCollectionItem();

		return $item ? $item->getId() : 0;
	}

	protected static function getPropertiesMap(array $documentType, array $context = []): array
	{
		$dynamicFilterFields = $context['Properties']['DynamicFilterFields'] ?? null;
		$properties = $context['Properties'] ?? [];
		$storageId = (int)($properties['StorageId'] ?? 0);
		$storages = StorageActivityService::getStorageTypes();
		$storageIds = array_map('intval', array_keys($storages));

		$filteringFields = StorageActivityService::getFilteringFieldsMapByStorageIds($storageIds);
		$filteringFieldsMap = [
			0 => array_values(StorageActivityService::getFilteringFieldsMap(0)),
		];

		foreach ($storages as $id => $title)
		{
			$filteringFieldsMap[$id] = array_values($filteringFields[$id] ?? []);
		}
		$writeFieldsMap = [];
		if ($storageId > 0)
		{
			$writeFieldsMap[$storageId] = self::getStorageFields($storageId);
		}
		$currentFieldValues = [];
		if (isset($properties['Fields']) && is_array($properties['Fields']))
		{
			$currentFieldValues = array_column($properties['Fields'], 'Value', 'FieldName');
		}

		return [
			'Author' => [
				'Name' => Loc::getMessage('BIZPROC_WRITE_DATA_ACTIVITY_RECORD_AUTHOR'),
				'FieldName' => 'Author',
				'Type' => FieldType::USER,
				'Required' => true,
				'AllowSelection' => true,
			],
			'StorageId' => [
				'Name' => Loc::getMessage('BIZPROC_WRITE_DATA_ACTIVITY_SELECT_STORAGE'),
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
				'Description' => Loc::getMessage('BIZPROC_WRITE_DATA_ACTIVITY_STORAGE_CODE'),
				'FieldName' => 'StorageCode',
				'Type' => FieldType::STRING,
				'Required' => false,
				'Hidden' => true,
			],
			'RewriteMode' => [
				'Name' => Loc::getMessage('BIZPROC_WRITE_DATA_ACTIVITY_RECORD_MODE'),
				'FieldName' => 'RewriteMode',
				'Type' => FieldType::SELECT,
				'Required' => true,
				'AllowSelection' => false,
				'Options' => [
					self::MODE_NEW_ITEM => Loc::getMessage('BIZPROC_WRITE_DATA_ACTIVITY_NEW_ITEM'),
					self::MODE_MERGE_FIELDS => Loc::getMessage('BIZPROC_WRITE_DATA_ACTIVITY_MERGE_FIELDS'),
					self::MODE_REWRITE_FIELDS => Loc::getMessage('BIZPROC_WRITE_DATA_ACTIVITY_REWRITE_FIELDS'),
				],
				'Default' => self::MODE_NEW_ITEM,
			],
			'DynamicFilterFields' => [
				'Name' => Loc::getMessage('BIZPROC_WRITE_DATA_ACTIVITY_FILTER_FIELDS_PROPERTY'),
				'FieldName' => 'filter_fields',
				'Type' => FieldType::CUSTOM,
				'Required' => false,
				'AllowSelection' => true,
				'CustomType' => 'filterFields',
				'Options' => [
					'documentType' => \Bitrix\Bizproc\Public\Entity\Document\Workflow::getComplexType(),
					'filteringFieldsPrefix' => 'filter_fields_',
					'filterFieldsMap' => $filteringFieldsMap,
					'conditions' => $dynamicFilterFields,
					'headCaption' => Loc::getMessage('BIZPROC_WRITE_DATA_ACTIVITY_FILTER_FIELDS_PROPERTY'),
					'collapsedCaption' => Loc::getMessage('BIZPROC_WRITE_DATA_ACTIVITY_FILTER_FIELDS_COLLAPSED_TEXT'),
				],
			],
			'WriteFields' => [
				'Name' => '',
				'FieldName' => 'write_fields',
				'Type' => FieldType::CUSTOM,
				'Required' => false,
				'AllowSelection' => true,
				'CustomType' => 'writeFields',
				'Options' => [
					'documentType' => \Bitrix\Bizproc\Public\Entity\Document\Workflow::getComplexType(),
					'writeFieldsMap' => $writeFieldsMap,
					'currentFieldValues' => $currentFieldValues,
					'addFieldCaption' => Loc::getMessage('BIZPROC_WRITE_DATA_ACTIVITY_FIELDS_ADD_FIELD'),
					'newFieldCaption' => Loc::getMessage('BIZPROC_WRITE_DATA_ACTIVITY_CREATE_NEW_FIELD'),
					'newStorageCaption' => Loc::getMessage('BIZPROC_WRITE_DATA_ACTIVITY_CREATE_NEW_STORAGE'),
				],
			],
			'Fields' => [
				'Name' => 'Fields',
				'FieldName' => 'Fields',
				'Type' => FieldType::SELECT,
				'Required' => false,
				'AllowSelection' => false,
				'Hidden' => true,
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
		];
	}

	protected static function extractPropertiesValues(PropertiesDialog $dialog, array $fieldsMap): Result
	{
		$simpleMap = $fieldsMap;
		unset($simpleMap['DynamicFilterFields'], $simpleMap['WriteFields'], $simpleMap['Fields']);
		$result = parent::extractPropertiesValues($dialog, $simpleMap);

		if (!$result->isSuccess())
		{
			return $result;
		}

		$currentValues = $result->getData();
		$formValues = $dialog->getCurrentValues();

		$currentValues['DynamicFilterFields'] = static::extractFilterFromProperties($dialog, $fieldsMap)->getData();

		$fieldKeys = static::normalizeFieldKeys($formValues['field_keys'] ?? null);

		if (!empty($fieldKeys))
		{
			$currentValues['Fields'] = self::extractFlatFieldValues($fieldKeys, $dialog, $currentValues);
		}
		else
		{
			$errors = [];
			$fallbackValues = $formValues;
			$fallbackValues['StorageId'] = StorageActivityService::resolveStorageId(
				isset($currentValues['StorageId']) ? (int)$currentValues['StorageId'] : null,
				(string)($currentValues['StorageCode'] ?? ''),
			);
			$currentValues['Fields'] = self::getStorageFieldValues(
				$dialog->getDocumentType(),
				$fallbackValues,
				$errors,
				static::getDocumentService(),
			);
		}

		$currentValues['FieldValue'] = array_column($currentValues['Fields'], 'Value', 'FieldName');

		$result->setData($currentValues);

		return $result;
	}

	private static function normalizeFieldKeys(mixed $fieldKeys): array
	{
		if (!is_array($fieldKeys))
		{
			return [];
		}

		$result = [];
		foreach ($fieldKeys as $key)
		{
			if (is_string($key) && $key !== '' && !isset($result[$key]))
			{
				$result[$key] = $key;
			}
		}

		return array_values($result);
	}

	private static function extractFlatFieldValues(
		array $fieldKeys,
		PropertiesDialog $dialog,
		array $currentValues,
	): array
	{
		$resolvedStorageId = StorageActivityService::resolveStorageId(
			isset($currentValues['StorageId']) ? (int)$currentValues['StorageId'] : null,
			(string)($currentValues['StorageCode'] ?? ''),
		);

		$storageFieldMap = $resolvedStorageId > 0
			? array_column(self::getStorageFields($resolvedStorageId), null, 'FieldName')
			: []
		;

		$documentType = $dialog->getDocumentType();
		$formValues = $dialog->getCurrentValues();
		$documentService = static::getDocumentService();
		$fields = [];

		foreach ($fieldKeys as $fieldName)
		{
			$controlName = self::FIELD_CONTROL_PREFIX . $fieldName . self::FIELD_CONTROL_SUFFIX;

			if (isset($storageFieldMap[$fieldName]))
			{
				$fieldProperties = $storageFieldMap[$fieldName];
				$typeObject = $documentService->getFieldTypeObject($documentType, $fieldProperties);

				if ($typeObject)
				{
					$value = $typeObject->extractValue(
						['Field' => $controlName],
						$formValues,
					);

					$prop = $typeObject->getProperty();
					$prop['FieldName'] = $fieldName;
					$prop['Value'] = $value;
					$fields[$fieldName] = $prop;

					continue;
				}
			}

			$fields[$fieldName] = [
				'FieldName' => $fieldName,
				'Value' => self::readRawFieldValue($controlName, $formValues),
			];
		}

		return $fields;
	}

	private static function readRawFieldValue(string $controlName, array $formValues): mixed
	{
		$value = $formValues[$controlName] ?? null;

		if (CBPHelper::isEmptyValue($value))
		{
			$text = $formValues[$controlName . '_text'] ?? null;
			if (is_string($text) && \CBPActivity::isExpression($text))
			{
				return $text;
			}
		}

		return $value;
	}

	public static function validateProperties(
		$arTestProperties = [],
		CBPWorkflowTemplateUser $user = null
	): array
	{
		$errors = [];

		$fieldsMap = static::getPropertiesMap([], ['Properties' => $arTestProperties]);
		foreach ($fieldsMap as $propertyKey => $fieldProperties)
		{
			if (
				array_key_exists('Required', $fieldProperties)
				&& CBPHelper::getBool($fieldProperties['Required'])
				&& CBPHelper::isEmptyValue($arTestProperties[$propertyKey] ?? null)
			)
			{
				$errors[] = [
					'code' => 'NotExist',
					'parameter' => $propertyKey,
					'message' => Loc::getMessage(
						'BIZPROC_WRITE_DATA_ACTIVITY_NOT_EXIST',
						['#PROPERTY#' => $fieldProperties['Name']]
					),
				];
			}
		}

		$fields = $arTestProperties['Fields'] ?? [];
		$fieldValues = array_column($fields, 'Value');
		if (is_array($fields) && !CBPHelper::isEmptyValue($fieldValues))
		{
			foreach ($fields as $fieldName => $field)
			{
				if (
					array_key_exists('Required', $field)
					&& CBPHelper::getBool($field['Required'])
					&& CBPHelper::isEmptyValue($field['Value'])
				)
				{
					$errors[] = [
						'code' => 'NotExist',
						'parameter' => $fieldName,
						'message' => Loc::getMessage(
							'BIZPROC_WRITE_DATA_ACTIVITY_NOT_EXIST',
							['#PROPERTY#' => $field['Name']]
						),
					];
				}
			}
		}
		else
		{
			$errors[] = [
				'code' => 'NotExist',
				'parameter' => 'Fields',
				'message' => Loc::getMessage('BIZPROC_WRITE_DATA_ACTIVITY_FIELDS_NOT_EXIST'),
			];
		}

		return $errors;
	}

	private static function getStorageFieldValues(
		array $documentType,
		array $currentValues,
		array &$errors,
		CBPDocumentService $documentService,
	): array
	{
		$storageId = $currentValues['StorageId'] ?? null;
		if (!$storageId)
		{
			return [];
		}

		$fields = [];
		$fieldMap = self::getStorageFields((int)$storageId);
		foreach ($fieldMap as $fieldProperties)
		{
			$field = $documentService->getFieldTypeObject($documentType, $fieldProperties);
			if (!$field)
			{
				continue;
			}

			$value = $field->extractValue(
				['Field' => $fieldProperties['FieldName']],
				$currentValues,
				$errors
			);

			$prop = $field->getProperty();
			$prop['FieldName'] = $fieldProperties['FieldName'];
			$prop['Value'] = $value;
			$fields[$fieldProperties['FieldName']] = $prop;
		}

		return $fields;
	}

	private static function getStorageFields(int $storageId): array
	{
		$fieldCollection = (new StorageFieldProvider())->getByStorageId($storageId);

		$result = [];
		foreach ($fieldCollection as $field)
		{
			$result[] = $field->toProperty();
		}

		return $result;
	}

	private function getComplexDocumentId(): array
	{
		return $this->complexDocumentId;
	}

	private function setComplexDocumentId(array $complexDocumentId): void
	{
		$this->complexDocumentId = $complexDocumentId;
	}
}
