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
use Bitrix\Bizproc\Public\Provider\StorageTypeProvider;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Bizproc\Automation\Engine\ConditionGroup;
use Bitrix\Bizproc\Activity\PropertiesDialog;
use Bitrix\Bizproc\FieldType;
use Bitrix\Bizproc\Internal\Service\StorageField\FieldService;

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
class CBPWriteDataStorageActivity extends CBPActivity
{
	use \Bitrix\Bizproc\Activity\Mixins\EntityFilter;

	private array $complexDocumentId = [];
	private const MODE_NEW_ITEM = 'newItem';
	private const MODE_MERGE_FIELDS = 'mergeFields';
	private const MODE_REWRITE_FIELDS = 'rewriteFields';

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

		$storageFields = self::getSystemFields() + self::getStorageFields((int)$this->StorageId);
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
			$provider = new StorageTypeProvider();
			$type = $provider->getType(['CODE' => $this->StorageCode], ['ID']);
			if ($type)
			{
				$this->StorageId = $type->getId();
			}
			else
			{
				$this->trackError(Loc::getMessage('BIZPROC_WRITE_DATA_ACTIVITY_STORAGE_NOT_FOUND') ?? '');
			}
		}
	}

	private function createNewStorageItem(array $fieldsData, int $author): Result
	{
		[$moduleId, $entity, $documentId] = $this->getComplexDocumentId();
		$templateId = $this->getWorkflowTemplateId();
		$workflowId = $this->getWorkflowInstanceId();

		$item = (new StorageItem())
			->setDocumentId($documentId)
			->setWorkflowId($workflowId)
			->setTemplateId($templateId)
			->setCode(CBPHelper::stringify($fieldsData['code'] ?? ''))
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
		[$moduleId, $entity, $documentId] = $this->getComplexDocumentId();
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
			if (!empty(CBPHelper::stringify($fieldsData['code'] ?? '')) && !$existingItem->getCode())
			{
				$existingItem->setCode(CBPHelper::stringify($fieldsData['code']));
			}

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
					$newValue= is_array($newValue) ? $newValue : [$newValue];
					$merged = array_merge($value, $newValue);
					$result[$key] = array_values(array_filter($merged, static fn($v) => $v !== null && $v !== ''));
				}
				//in other cases keep the current data
			}

			$existingItem->setValueFields($result);
		}
		else
		{
			if (!empty(CBPHelper::stringify($fieldsData['code'] ?? '')))
			{
				$existingItem->setCode(CBPHelper::stringify($fieldsData['code']));
			}

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

	public static function getPropertiesDialog(
		$documentType,
		$activityName,
		$arWorkflowTemplate,
		$arWorkflowParameters,
		$arWorkflowVariables,
		$arCurrentValues = null,
		$formName = '',
		$popupWindow = null,
		$siteId = ''
	)
	{
		$dialog = new PropertiesDialog(__FILE__, [
			'documentType' => $documentType,
			'activityName' => $activityName,
			'workflowTemplate' => $arWorkflowTemplate,
			'workflowParameters' => $arWorkflowParameters,
			'workflowVariables' => $arWorkflowVariables,
			'currentValues' => $arCurrentValues,
			'formName' => $formName,
			'siteId' => $siteId,
		]);

		$dialog->setMap(static::getPropertiesMap($documentType));
		$storageId = $dialog->getCurrentValue('StorageId');
		if ($storageId)
		{
			$dynamicFields = self::getStorageFields((int)$storageId);
			$systemFields = array_values(self::getSystemFields());
			$runtimeData = [
				'fields' => $dynamicFields,
				'systemFields' => $systemFields,
			];

			$dialog->setRuntimeData(array_merge($dialog->getRuntimeData(), $runtimeData));
		}
		else
		{
			$dialog->setRuntimeData(array_merge($dialog->getRuntimeData(), [
				'systemFields' => array_values(self::getSystemFields())
			]));
		}

		return $dialog;
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

	protected function findStorageItemId(): int
	{
		if (!$this->StorageId)
		{
			$this->findStorageTypeId();
		}

		$conditionGroup = new ConditionGroup((array)($this->DynamicFilterFields ?? []));
		$provider = new StorageItemProvider((int)$this->StorageId);

		$documentType = \Bitrix\Bizproc\Public\Entity\Document\Workflow::getComplexType();
		$fieldsMap = static::getFilteringFieldsMap($this->StorageId);
		$filter = $this->getOrmFilter($conditionGroup, $documentType, $fieldsMap);
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
		$provider = new StorageTypeProvider();
		$storages = $provider->getAllForActivity();

		$options = [];

		foreach ($storages as $storage)
		{
			$options[] = [
				'id' => (int)$storage->getId(),
				'title' => $storage->getTitle(),
			];
		}

		$filteringFieldsMap = [
			0 => array_values(static::getFilteringFieldsMap(0))
		];
		$storages = static::getStorageTypes();

		foreach ($storages as $id => $title)
		{
			$filteringFieldsMap[$id] = array_values(static::getFilteringFieldsMap($id));
		}

		return [
			'Author' => [
				'Name' => Loc::getMessage('BIZPROC_WRITE_DATA_ACTIVITY_RECORD_AUTHOR'),
				'FieldName' => 'Author',
				'Type' => 'user',
				'Required' => true,
				'AllowSelection' => true,
			],
			'StorageId' => [
				'Name' => Loc::getMessage('BIZPROC_WRITE_DATA_ACTIVITY_SELECT_STORAGE'),
				'FieldName' => 'StorageId',
				'Type' => 'select',
				'Required' => false,
				'Options' => $options,
				'AllowSelection' => false,
			],
			'StorageCode' => [
				'Name' => '',
				'Description' => Loc::getMessage('BIZPROC_WRITE_DATA_ACTIVITY_STORAGE_CODE'),
				'FieldName' => 'StorageCode',
				'Type' => 'string',
				'Required' => false,
				'AllowSelection' => true,
			],
			'RewriteMode' => [
				'Name' => Loc::getMessage('BIZPROC_WRITE_DATA_ACTIVITY_RECORD_MODE'),
				'FieldName' => 'RewriteMode',
				'Type' => 'select',
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
				'Name' => 'ID',
				'FieldName' => 'DynamicFilterFields',
				'Map' => $filteringFieldsMap,
			],
			'Fields' => [
				'Name' => 'Fields',
				'FieldName' => 'Fields',
				'Type' => 'select',
				'Required' => false,
				'AllowSelection' => false,
				'Hidden' => true,
			],
			'IsExpanded' => [
				'Name' => '',
				'FieldName' => 'IsExpanded',
				'Type' => FieldType::STRING,
				'Required' => false,
				'AllowSelection' => false,
				'Hidden' => true,
				'Default' => 'Y',
			],
		];
	}

	public static function getPropertiesDialogValues(
		$documentType,
		$activityName,
		&$workflowTemplate,
		&$workflowParameters,
		&$workflowVariables,
		$currentValues,
		&$errors
	)
	{
		$errors = [];
		$properties = ['FieldValue' => []];

		$runtime = CBPRuntime::GetRuntime();
		$runtime->StartRuntime();
		/** @var CBPDocumentService $documentService */
		$documentService = $runtime->getService('DocumentService');

		$fieldsMap = static::getPropertiesMap($documentType);
		foreach ($fieldsMap as $propertyKey => $fieldProperties)
		{
			$field = $documentService->getFieldTypeObject($documentType, $fieldProperties);
			if (!$field || $propertyKey === 'Fields')
			{
				continue;
			}

			$properties[$fieldProperties['FieldName']] = $field->extractValue(
				['Field' => $fieldProperties['FieldName']],
				$currentValues,
				$errors,
			);
		}

		$fields = [];
		$fieldKeys = $currentValues['field_keys'] ?? [];
		$fieldValues = $currentValues['field_values'] ?? [];
		foreach ($fieldKeys as $index => $key)
		{
			$fields[$key] = [
				'Value' => $fieldValues[$index] ?? null,
				'FieldName' => $key,
			];
		}

		if (!empty($fields))
		{
			$properties['Fields'] = $fields;
		}
		else
		{
			$properties['Fields'] = self::getStorageFieldValues(
				$documentType,
				$currentValues,
				$errors,
				$documentService,
			);
		}

		$errors = self::validateProperties(
			$properties,
			new CBPWorkflowTemplateUser(CBPWorkflowTemplateUser::CurrentUser),
		);

		if ($errors)
		{
			return false;
		}

		$dialog = new PropertiesDialog(__FILE__, [
			'documentType' => $documentType,
			'activityName' => $activityName,
			'workflowTemplate' => $workflowTemplate,
			'workflowParameters' => $workflowParameters,
			'workflowVariables' => $workflowVariables,
			'currentValues' => $currentValues,
		]);

		$properties['FieldValue'] = array_column($properties['Fields'], 'Value', 'FieldName');
		$currentActivity = &CBPWorkflowTemplateLoader::FindActivityByName($workflowTemplate, $activityName);
		$properties['DynamicFilterFields'] = static::extractFilterFromProperties($dialog, $fieldsMap)->getData();
		$currentActivity['Properties'] = $properties;

		return true;
	}

	public static function validateProperties(
		$arTestProperties = [],
		CBPWorkflowTemplateUser $user = null
	): array
	{
		$errors = [];

		$fieldsMap = static::getPropertiesMap($arTestProperties, ['RewriteMode' => $arTestProperties['RewriteMode'] ?? '']);
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
		$fieldMap = self::getSystemFields() + self::getStorageFields((int)$storageId);
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

	private static function getSystemFields(): array
	{
		return [
			'ItemCode' => [
				'Id' => 'code',
				'Name' => Loc::getMessage('BIZPROC_WRITE_DATA_ACTIVITY_RECORD_CODE'),
				'FieldName' => 'code',
				'Type' => 'string',
				'Required' => false,
				'AllowSelection' => true,
			],
		];
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
