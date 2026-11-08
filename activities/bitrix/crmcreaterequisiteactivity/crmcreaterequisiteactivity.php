<?php

declare(strict_types=1);

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\Bizproc\Activity\BaseActivity;
use Bitrix\Bizproc\Activity\Mixins\TargetDocumentResolverTrait;
use Bitrix\Bizproc\Activity\PropertiesDialog;
use Bitrix\Bizproc\FieldType;
use Bitrix\Crm\EntityAddressType;
use Bitrix\Crm\EntityPreset;
use Bitrix\Crm\EntityRequisite;
use Bitrix\Crm\Integration\BizProc\Activity\CreateRequisiteOperation;
use Bitrix\Crm\Integration\BizProc\Activity\RequisiteActivityForm;
use Bitrix\Crm\Item;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\Factory;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;

Loc::loadMessages(__FILE__);

final class CBPCrmCreateRequisiteActivity extends BaseActivity implements IBPConfigurableActivity
{
	use TargetDocumentResolverTrait;

	private const PROPERTY_CRM_ENTITY_TYPE = 'CrmEntityType';
	private const PROPERTY_CRM_ENTITY_ID = 'CrmEntityId';
	private const PROPERTY_REQUISITE_PRESET_ID = 'RequisitePresetId';
	private const PROPERTY_COUNTRY_ID = 'CountryId';
	private const PROPERTY_ADDRESS_TYPE_ID = 'AddressTypeId';
	private const PROPERTY_REQUISITE_NAME = 'RequisiteName';
	private const PROPERTY_MODIFIED_BY = 'ModifiedBy';
	private const PROPERTY_FIELDS_VALUES = 'FieldsValues';

	private const RETURN_REQUISITE_ID = 'RequisiteId';
	private const RETURN_STATUS = 'Status';
	private const RETURN_ERROR_MESSAGE = 'ErrorMessage';
	private const RETURN_WARNINGS = 'Warnings';

	private const STATUS_ERROR = 'error';

	protected static $requiredModules = ['crm'];

	public function __construct($name)
	{
		parent::__construct($name);

		$this->arProperties = [
			'Title' => '',
			self::PROPERTY_CRM_ENTITY_TYPE => null,
			self::PROPERTY_CRM_ENTITY_ID => null,
			self::PROPERTY_REQUISITE_PRESET_ID => null,
			self::PROPERTY_COUNTRY_ID => null,
			self::PROPERTY_ADDRESS_TYPE_ID => null,
			self::PROPERTY_REQUISITE_NAME => null,
			self::PROPERTY_MODIFIED_BY => null,
			self::PROPERTY_FIELDS_VALUES => null,

			self::RETURN_REQUISITE_ID => 0,
			self::RETURN_STATUS => self::STATUS_ERROR,
			self::RETURN_ERROR_MESSAGE => '',
		];

		$this->setPropertiesTypes([
			self::PROPERTY_CRM_ENTITY_TYPE => ['Type' => FieldType::INT],
			self::PROPERTY_CRM_ENTITY_ID => ['Type' => FieldType::INT],
			self::PROPERTY_REQUISITE_PRESET_ID => ['Type' => FieldType::INT],
			self::PROPERTY_COUNTRY_ID => ['Type' => FieldType::INT],
			self::PROPERTY_ADDRESS_TYPE_ID => ['Type' => FieldType::INT],
			self::PROPERTY_REQUISITE_NAME => ['Type' => FieldType::STRING],
			self::PROPERTY_MODIFIED_BY => ['Type' => 'user'],
			self::RETURN_REQUISITE_ID => ['Type' => FieldType::INT],
			self::RETURN_STATUS => ['Type' => FieldType::SELECT],
			self::RETURN_ERROR_MESSAGE => ['Type' => FieldType::STRING],
		]);
	}

	public function execute(): int
	{
		$this->setReturnValues(0, self::STATUS_ERROR, '');

		if (!self::checkModules())
		{
			$this->completeWithError($this->getMessage(
				'CRM_CREATE_REQUISITE_ACTIVITY_ERROR_MODULES',
				'Required modules are not installed.',
			));

			return CBPActivityExecutionStatus::Closed;
		}

		try
		{
			$targetDocumentId = $this->resolveTargetDocumentId();
			$target = $this->resolveClientWithRequisites($targetDocumentId);
			if ($target['ownerTypeId'] <= 0 || $target['ownerId'] <= 0)
			{
				$this->completeWithError($this->getMessage(
					'CRM_CREATE_REQUISITE_ACTIVITY_ERROR_CLIENT_NOT_FOUND',
					'CRM client was not found.',
				));

				return CBPActivityExecutionStatus::Closed;
			}

			$presetId = $this->getParsedIntProperty(self::PROPERTY_REQUISITE_PRESET_ID);
			if (
				$presetId <= 0
				|| !EntityRequisite::getSingleInstance()->validateEntityExists($target['ownerTypeId'], $target['ownerId'])
				|| !self::isActiveRequisitePreset($presetId)
			)
			{
				$this->completeWithError($this->getMessage(
					'CRM_CREATE_REQUISITE_ACTIVITY_ERROR_PRESET_NOT_FOUND',
					'Requisite template is not available for the selected client.',
				));

				return CBPActivityExecutionStatus::Closed;
			}

			$operation = new CreateRequisiteOperation(
				messageResolver: fn(string $code, array $replace, string $fallback): string => $this->getMessage(
					$code,
					$fallback,
					$replace,
				),
			);

			$result = $operation->execute(
				$target['ownerTypeId'],
				$target['ownerId'],
				$presetId,
				fn(): array => $this->buildCreationData($targetDocumentId, $target['documentTypeId'], $target['documentId']),
			);

			$this->applyOperationResult($result);
		}
		catch (Throwable)
		{
			$this->completeWithError($this->getMessage(
				'CRM_CREATE_REQUISITE_ACTIVITY_ERROR_UNEXPECTED',
				'Unable to create requisite. Try again.',
			));
		}

		return CBPActivityExecutionStatus::Closed;
	}

	public static function getPropertiesDialog(
		$documentType,
		$activityName,
		$workflowTemplate,
		$workflowParameters,
		$workflowVariables,
		$currentValues = null,
		$formName = '',
		$popupWindow = null,
		$siteId = '',
	)
	{
		if (!self::checkModules())
		{
			return false;
		}

		$dialog = new PropertiesDialog(self::getFileName(), [
			'documentType' => $documentType,
			'activityName' => $activityName,
			'workflowTemplate' => $workflowTemplate,
			'workflowParameters' => $workflowParameters,
			'workflowVariables' => $workflowVariables,
			'currentValues' => $currentValues,
			'formName' => $formName,
			'siteId' => $siteId,
		]);
		$dialog
			->setMapCallback([self::class, 'getPropertiesDialogMap'])
			->setRuntimeData(self::getRuntimeData())
		;

		return RequisiteActivityForm::configureDialog(
			$dialog,
			self::getChangeRequisiteActivityFile(),
			true,
			self::getBankDetailNameTitle(),
			true,
		);
	}

	public static function getPropertiesDialogValues(
		$documentType,
		$activityName,
		&$workflowTemplate,
		&$workflowParameters,
		&$workflowVariables,
		$currentValues,
		&$errors,
	): bool
	{
		if (!self::checkModules())
		{
			return false;
		}

		$runtime = \CBPRuntime::GetRuntime();
		$runtime->StartRuntime();
		$documentService = $runtime->GetService('DocumentService');

		$properties = RequisiteActivityForm::getValues(
			$documentType,
			$documentService,
			self::getPropertiesDialogMap(),
			$currentValues,
			$errors,
		);
		$directClientTypeId = self::resolveDirectClientTypeId($documentType);
		if ($directClientTypeId > 0)
		{
			$properties[self::PROPERTY_CRM_ENTITY_TYPE] = $directClientTypeId;
		}
		$properties[self::PROPERTY_FIELDS_VALUES] = RequisiteActivityForm::extractFieldsValues(
			$documentType,
			$documentService,
			$currentValues,
			$errors,
			$properties[self::PROPERTY_REQUISITE_PRESET_ID] ?? null,
			[],
			false,
			true,
			self::getBankDetailNameTitle(),
			true,
		);

		$errors = self::ValidateProperties(
			$properties,
			new \CBPWorkflowTemplateUser(\CBPWorkflowTemplateUser::CurrentUser),
		);
		if ($errors)
		{
			return false;
		}

		$currentActivity = &\CBPWorkflowTemplateLoader::FindActivityByName($workflowTemplate, $activityName);
		$currentActivity['Properties'] = $properties;

		return true;
	}

	protected static function getPropertiesMap(array $documentType, array $context = []): array
	{
		$properties = is_array($context['Properties'] ?? null) ? $context['Properties'] : [];
		$dialog = new PropertiesDialog(self::getFileName(), [
			'documentType' => $documentType,
			'currentValues' => $properties,
		]);
		$map = self::getPropertiesDialogMap($dialog);
		if (isset($map[self::PROPERTY_CRM_ENTITY_TYPE]))
		{
			$map[self::PROPERTY_CRM_ENTITY_TYPE]['Getter'] = static function (
				PropertiesDialog $dialog,
				array $property,
				array $currentActivity,
			) use ($documentType): mixed
			{
				$directClientTypeId = self::resolveDirectClientTypeId($documentType);
				if ($directClientTypeId > 0)
				{
					return $directClientTypeId;
				}

				return $currentActivity['Properties'][self::PROPERTY_CRM_ENTITY_TYPE] ?? null;
			};
		}

		return array_merge(
			$map,
			self::getFieldsValuesPropertiesMap($properties),
		);
	}

	public static function getPropertiesDialogMap(?PropertiesDialog $dialog = null): array
	{
		$map = [
			self::PROPERTY_CRM_ENTITY_TYPE => [
				'Name' => self::getStaticMessage('CRM_CREATE_REQUISITE_ACTIVITY_FIELD_CLIENT_TYPE', 'Client type'),
				'FieldName' => 'crm_entity_type',
				'Type' => FieldType::SELECT,
				'Required' => true,
				'Options' => [
					\CCrmOwnerType::Company => \CCrmOwnerType::GetDescription(\CCrmOwnerType::Company),
					\CCrmOwnerType::Contact => \CCrmOwnerType::GetDescription(\CCrmOwnerType::Contact),
				],
				'Getter' => static function (PropertiesDialog $dialog, array $property, array $currentActivity): mixed
				{
					$directClientTypeId = self::resolveDirectClientTypeId($dialog->getDocumentType());
					if ($directClientTypeId > 0)
					{
						return $directClientTypeId;
					}

					return $currentActivity['Properties'][self::PROPERTY_CRM_ENTITY_TYPE] ?? null;
				},
			],
			self::PROPERTY_REQUISITE_PRESET_ID => [
				'Name' => self::getStaticMessage('CRM_CREATE_REQUISITE_ACTIVITY_FIELD_REQUISITE_PRESET', 'Requisite template'),
				'FieldName' => 'requisite_preset',
				'Type' => FieldType::SELECT,
				'Required' => true,
				'Options' => self::getRequisitePresetOptions(),
			],
			self::PROPERTY_COUNTRY_ID => [
				'Name' => self::getStaticMessage('CRM_CREATE_REQUISITE_ACTIVITY_FIELD_COUNTRY', 'Country'),
				'FieldName' => 'country_id',
				'Type' => FieldType::SELECT,
				'Options' => self::getCountryOptions(),
			],
			self::PROPERTY_ADDRESS_TYPE_ID => [
				'Name' => self::getStaticMessage('CRM_CREATE_REQUISITE_ACTIVITY_FIELD_ADDRESS_TYPE', 'Address type'),
				'FieldName' => 'address_type_id',
				'Type' => FieldType::SELECT,
				'Options' => array_filter(
					EntityAddressType::getAllDescriptions(),
					static fn($value): bool => !CBPHelper::isEmptyValue($value),
				),
			],
			self::PROPERTY_REQUISITE_NAME => [
				'Name' => self::getStaticMessage('CRM_CREATE_REQUISITE_ACTIVITY_FIELD_REQUISITE_NAME', 'New requisite name'),
				'FieldName' => 'requisite_name',
				'Type' => FieldType::STRING,
				'Required' => true,
				'AllowSelection' => true,
			],
			self::PROPERTY_MODIFIED_BY => [
				'Name' => self::getStaticMessage('CRM_CREATE_REQUISITE_ACTIVITY_FIELD_MODIFIED_BY', 'Modified by'),
				'FieldName' => 'modified_by',
				'Type' => 'user',
				'AllowSelection' => true,
			],
			self::PROPERTY_FIELDS_VALUES => [
				'FieldName' => self::PROPERTY_FIELDS_VALUES,
				'Getter' => static function (
					PropertiesDialog $dialog,
					array $property,
					array $currentActivity,
					bool $compatible,
				): array
				{
					$fieldsValues = RequisiteActivityForm::normalizeFieldsValues(
						$currentActivity['Properties'][self::PROPERTY_FIELDS_VALUES] ?? [],
					);

					return array_merge(
						$fieldsValues[RequisiteActivityForm::REQUISITE_FIELDS],
						$fieldsValues[RequisiteActivityForm::BANK_DETAIL_FIELDS],
					);
				},
			],
		];

		$directClientTypeId = $dialog !== null
			? self::resolveDirectClientTypeId($dialog->getDocumentType())
			: 0;
		if ($directClientTypeId > 0)
		{
			$map[self::PROPERTY_CRM_ENTITY_TYPE]['Hidden'] = true;
			$map[self::PROPERTY_CRM_ENTITY_TYPE]['Default'] = $directClientTypeId;
		}

		return $map;
	}

	private static function getFieldsValuesPropertiesMap(array $properties): array
	{
		$fieldsValues = RequisiteActivityForm::normalizeFieldsValues([]);
		$addressTypeId = self::resolveMapAddressTypeId($properties);
		$countryId = 0;
		$presetId = self::resolveMapPresetId($properties);
		if ($presetId > 0)
		{
			$countryId = EntityRequisite::getSingleInstance()->getCountryIdByPresetId($presetId);
		}

		return array_merge(
			self::attachFieldsValuesAccessors(
				array_merge(
					RequisiteActivityForm::getRequisiteFieldsMap($countryId),
					RequisiteActivityForm::getUserFieldsMap(),
				),
				RequisiteActivityForm::REQUISITE_FIELDS,
				$fieldsValues,
				$addressTypeId,
			),
			self::attachFieldsValuesAccessors(
				RequisiteActivityForm::getAddressFieldsMap(),
				EntityRequisite::ADDRESS,
				$fieldsValues,
				$addressTypeId,
			),
			self::attachFieldsValuesAccessors(
				RequisiteActivityForm::getBankDetailMap(true, self::getBankDetailNameTitle()),
				RequisiteActivityForm::BANK_DETAIL_FIELDS,
				$fieldsValues,
				$addressTypeId,
			),
		);
	}

	private static function attachFieldsValuesAccessors(
		array $map,
		string $sectionName,
		array &$fieldsValues,
		int $addressTypeId,
	): array
	{
		foreach ($map as $fieldName => &$property)
		{
			$fieldName = (string)$fieldName;
			$property['Getter'] = static function (
				PropertiesDialog $dialog,
				array $property,
				array $currentActivity,
			) use ($sectionName, $fieldName): mixed
			{
				return self::getFieldsValue($currentActivity['Properties'] ?? [], $sectionName, $fieldName);
			};
			$property['Setter'] = static function (mixed $value) use (
				&$fieldsValues,
				$sectionName,
				$fieldName,
				$addressTypeId,
			): array
			{
				self::setFieldsValue($fieldsValues, $sectionName, $fieldName, $value, $addressTypeId);

				return [self::PROPERTY_FIELDS_VALUES => $fieldsValues];
			};
		}
		unset($property);

		return $map;
	}

	private static function getFieldsValue(array $properties, string $sectionName, string $fieldName): mixed
	{
		$fieldsValues = RequisiteActivityForm::normalizeFieldsValues($properties[self::PROPERTY_FIELDS_VALUES] ?? []);
		if ($sectionName === EntityRequisite::ADDRESS)
		{
			$addressTypeId = self::resolveMapAddressTypeId($properties);
			$value
				= $fieldsValues[RequisiteActivityForm::REQUISITE_FIELDS][EntityRequisite::ADDRESS][$addressTypeId][$fieldName]
				?? null;
			if ($value !== null)
			{
				return $value;
			}

			$addressValues = $fieldsValues[RequisiteActivityForm::REQUISITE_FIELDS][EntityRequisite::ADDRESS] ?? [];
			foreach ($addressValues as $addressFields)
			{
				if (is_array($addressFields) && array_key_exists($fieldName, $addressFields))
				{
					return $addressFields[$fieldName];
				}
			}

			return null;
		}

		return $fieldsValues[$sectionName][$fieldName] ?? null;
	}

	private static function setFieldsValue(
		array &$fieldsValues,
		string $sectionName,
		string $fieldName,
		mixed $value,
		int $addressTypeId,
	): void
	{
		if ($sectionName === EntityRequisite::ADDRESS)
		{
			$fieldsValues[RequisiteActivityForm::REQUISITE_FIELDS][EntityRequisite::ADDRESS][$addressTypeId][$fieldName]
				= $value;

			return;
		}

		$fieldsValues[$sectionName][$fieldName] = $value;
	}

	private static function resolveMapPresetId(array $properties): int
	{
		$presetId = $properties[self::PROPERTY_REQUISITE_PRESET_ID] ?? $properties['requisite_preset'] ?? null;

		return is_scalar($presetId) && is_numeric($presetId) ? (int)$presetId : 0;
	}

	private static function resolveMapAddressTypeId(array $properties): int
	{
		$addressTypeId = $properties[self::PROPERTY_ADDRESS_TYPE_ID] ?? $properties['address_type_id'] ?? null;

		return is_scalar($addressTypeId) && is_numeric($addressTypeId) ? (int)$addressTypeId : 0;
	}

	public static function validateProperties($testProperties = [], ?\CBPWorkflowTemplateUser $user = null)
	{
		$testProperties[self::PROPERTY_FIELDS_VALUES] = RequisiteActivityForm::normalizeFieldsValues(
			$testProperties[self::PROPERTY_FIELDS_VALUES] ?? [],
		);
		$errors = parent::validateProperties($testProperties, $user);
		$errors = self::normalizeRequisiteNameValidationErrors($errors, $testProperties);

		$presetMap = self::getPropertiesDialogMap()[self::PROPERTY_REQUISITE_PRESET_ID] ?? [];
		$presetValue = $testProperties[self::PROPERTY_REQUISITE_PRESET_ID] ?? null;
		if (!RequisiteActivityForm::isValueVariable($presetMap, $presetValue))
		{
			$presetId = (int)$presetValue;
			foreach (RequisiteActivityForm::getRequiredFieldNames($presetId) as $fieldName)
			{
				if (RequisiteActivityForm::isFieldConfigured($testProperties[self::PROPERTY_FIELDS_VALUES], $fieldName))
				{
					continue;
				}

				$errors[] = [
					'code' => 'NotExist',
					'parameter' => 'FieldValue',
					'message' => self::getStaticMessage(
						'CRM_CREATE_REQUISITE_ACTIVITY_ERROR_REQUIRED_FIELD_NOT_CONFIGURED',
						'Required field "#FIELD#" is not configured.',
						['#FIELD#' => RequisiteActivityForm::getFieldNameForError($fieldName, $presetId)],
					),
				];
			}
		}

		$bankDetailFields = $testProperties[self::PROPERTY_FIELDS_VALUES][RequisiteActivityForm::BANK_DETAIL_FIELDS];
		if (
			RequisiteActivityForm::hasBankDetailFieldsWithoutName($bankDetailFields)
			&& \CBPHelper::isEmptyValue($bankDetailFields[RequisiteActivityForm::BANK_DETAIL_NAME] ?? null)
		)
		{
			$errors[] = [
				'code' => 'NotExist',
				'parameter' => 'FieldValue',
				'message' => self::getStaticMessage(
					'CRM_CREATE_REQUISITE_ACTIVITY_ERROR_BANK_DETAIL_NAME_NOT_CONFIGURED',
					'Bank detail name is required when bank detail fields are configured.',
				),
			];
		}

		return $errors;
	}

	protected static function getFileName(): string
	{
		return __FILE__;
	}

	private static function normalizeRequisiteNameValidationErrors(array $errors, array $testProperties): array
	{
		if (!\CBPHelper::isEmptyValue($testProperties[self::PROPERTY_REQUISITE_NAME] ?? null))
		{
			return $errors;
		}

		$fieldName = self::getPropertiesDialogMap()[self::PROPERTY_REQUISITE_NAME]['Name'];
		foreach ($errors as $index => $error)
		{
			if (
				($error['parameter'] ?? null) === 'FieldValue'
				&& is_string($error['message'] ?? null)
				&& str_contains($error['message'], $fieldName)
			)
			{
				unset($errors[$index]);
			}
		}

		$errors[] = [
			'code' => 'NotExist',
			'parameter' => self::PROPERTY_REQUISITE_NAME,
			'message' => self::getStaticMessage(
				'CRM_CREATE_REQUISITE_ACTIVITY_ERROR_NAME_EMPTY',
				'Requisite name is required.',
			),
		];

		return array_values($errors);
	}

	private function buildCreationData(array $targetDocumentId, int $documentTypeId, int $documentId): array
	{
		$fieldsValues = $this->normalizeFieldsValues($this->getParsedProperty(self::PROPERTY_FIELDS_VALUES));
		$requisiteFields = RequisiteActivityForm::normalizeBooleanUserFieldValues(
			$fieldsValues[RequisiteActivityForm::REQUISITE_FIELDS],
			RequisiteActivityForm::getUserFieldsMap(),
		);
		$addressTypeId = $this->getParsedIntProperty(self::PROPERTY_ADDRESS_TYPE_ID);
		$addressFields = $this->extractAddressFields($requisiteFields, $addressTypeId);
		$bankDetailFields = $fieldsValues[RequisiteActivityForm::BANK_DETAIL_FIELDS];

		unset($requisiteFields[EntityRequisite::ADDRESS]);

		[$authorId, $authorWarning] = $this->resolveAuthorId($targetDocumentId, $documentTypeId, $documentId);

		return [
			'name' => $this->getParsedProperty(self::PROPERTY_REQUISITE_NAME, FieldType::STRING),
			'addressTypeId' => $addressTypeId,
			'authorId' => $authorId,
			'countryId' => EntityRequisite::getSingleInstance()->getCountryIdByPresetId(
				$this->getParsedIntProperty(self::PROPERTY_REQUISITE_PRESET_ID),
			),
			'requisiteFields' => $requisiteFields,
			'addressFields' => $addressFields,
			'bankDetailFields' => $bankDetailFields,
			'warnings' => $authorWarning !== '' ? [$authorWarning] : [],
		];
	}

	private function resolveClientWithRequisites(array $targetDocumentId): array
	{
		[$documentTypeId, $documentId] = \CCrmBizProcHelper::resolveEntityId($targetDocumentId);
		$result = [
			'documentTypeId' => (int)$documentTypeId,
			'documentId' => (int)$documentId,
			'ownerTypeId' => 0,
			'ownerId' => 0,
		];

		if ($documentTypeId === \CCrmOwnerType::Company || $documentTypeId === \CCrmOwnerType::Contact)
		{
			$result['ownerTypeId'] = (int)$documentTypeId;
			$result['ownerId'] = (int)$documentId;

			return $result;
		}

		$clientTypeId = $this->getParsedIntProperty(self::PROPERTY_CRM_ENTITY_TYPE);
		if ($clientTypeId !== \CCrmOwnerType::Company && $clientTypeId !== \CCrmOwnerType::Contact)
		{
			return $result;
		}

		$clientFieldName = \CCrmOwnerType::ResolveName($clientTypeId) . '_ID';
		$entity = $this->getCrmEntityFields((int)$documentTypeId, (int)$documentId);
		$clientId = (int)($entity[$clientFieldName] ?? 0);

		if ($clientId > 0)
		{
			$result['ownerTypeId'] = $clientTypeId;
			$result['ownerId'] = $clientId;
		}

		return $result;
	}

	private function getCrmEntityFields(int $entityTypeId, int $entityId): array
	{
		if ($entityId <= 0)
		{
			return [];
		}

		switch ($entityTypeId)
		{
			case \CCrmOwnerType::Lead:
				return (array)\CCrmLead::GetByID($entityId, false);

			case \CCrmOwnerType::Deal:
				return (array)\CCrmDeal::GetByID($entityId, false);

			case \CCrmOwnerType::Contact:
				return (array)\CCrmContact::GetByID($entityId, false);

			case \CCrmOwnerType::Company:
				return (array)\CCrmCompany::GetByID($entityId, false);
		}

		$quoteTypeId = defined('CCrmOwnerType::Quote') ? \CCrmOwnerType::Quote : null;
		if ($quoteTypeId !== null && $entityTypeId === $quoteTypeId && class_exists(\CCrmQuote::class))
		{
			return (array)\CCrmQuote::GetByID($entityId, false);
		}

		$factory = Container::getInstance()->getFactory($entityTypeId);
		if ($factory === null)
		{
			return [];
		}

		$item = $this->getFactoryItem($factory, $entityId);
		if ($item === null)
		{
			return [];
		}

		$fields = [];
		foreach ([\CCrmOwnerType::ResolveName(\CCrmOwnerType::Contact) . '_ID', \CCrmOwnerType::ResolveName(\CCrmOwnerType::Company) . '_ID'] as $fieldName)
		{
			$fields[$fieldName] = (int)$this->getItemValue($item, $fieldName);
		}
		$fields['ASSIGNED_BY_ID'] = $this->getItemAssignedById($item);

		return $fields;
	}

	private function getFactoryItem(Factory $factory, int $entityId): ?Item
	{
		$fieldsToSelect = [];
		foreach ([Item::FIELD_NAME_CONTACT_ID, Item::FIELD_NAME_COMPANY_ID, Item::FIELD_NAME_ASSIGNED] as $fieldName)
		{
			if ($factory->isFieldExists($fieldName))
			{
				$fieldsToSelect[] = $fieldName;
			}
		}

		return $factory->getItem($entityId, $fieldsToSelect);
	}

	private function getItemValue(object $item, string $fieldName): mixed
	{
		try
		{
			return $item->get($fieldName);
		}
		catch (Throwable)
		{
			return null;
		}
	}

	private function getItemAssignedById(object $item): int
	{
		if (method_exists($item, 'getAssignedById'))
		{
			try
			{
				return (int)$item->getAssignedById();
			}
			catch (Throwable)
			{
				return 0;
			}
		}

		return (int)$this->getItemValue($item, 'ASSIGNED_BY_ID');
	}

	private function resolveAuthorId(array $targetDocumentId, int $documentTypeId, int $documentId): array
	{
		$modifiedBy = (int)CBPHelper::ExtractUsers(
			$this->getParsedProperty(self::PROPERTY_MODIFIED_BY),
			$targetDocumentId,
			true,
		);
		if ($modifiedBy > 0)
		{
			return [$modifiedBy, ''];
		}

		$startedBy = $this->workflow ? (int)$this->workflow->getStartedBy() : 0;
		if ($startedBy > 0)
		{
			return [$startedBy, ''];
		}

		$responsibleId = (int)($this->getCrmEntityFields($documentTypeId, $documentId)['ASSIGNED_BY_ID'] ?? 0);
		if ($responsibleId > 0)
		{
			return [$responsibleId, ''];
		}

		return [
			0,
			$this->getMessage(
				'CRM_CREATE_REQUISITE_ACTIVITY_AUTHOR_NOT_FOUND',
				'Requisite author was resolved to system user because Modified by, workflow starter and source document responsible user are empty.',
			),
		];
	}

	private function normalizeFieldsValues(mixed $fieldsValues): array
	{
		return RequisiteActivityForm::normalizeFieldsValues($fieldsValues);
	}

	private function extractAddressFields(array $requisiteFields, int $addressTypeId): array
	{
		$addresses = $requisiteFields[EntityRequisite::ADDRESS] ?? [];
		if (!is_array($addresses))
		{
			return [];
		}

		if ($addressTypeId > 0 && is_array($addresses[$addressTypeId] ?? null))
		{
			return $addresses[$addressTypeId];
		}

		$firstAddress = reset($addresses);

		return is_array($firstAddress) ? $firstAddress : [];
	}

	private function getParsedIntProperty(string $propertyId): int
	{
		return (int)$this->getParsedProperty($propertyId, FieldType::INT);
	}

	private function getParsedProperty(string $propertyId, string $type = ''): mixed
	{
		return $this->convertPropertyValue($type, $this->parseValue($this->getRawProperty($propertyId)));
	}

	private function applyOperationResult(Result $result): void
	{
		$data = $result->getData();
		$errorMessage = (string)($data[self::RETURN_ERROR_MESSAGE] ?? '');

		$this->setReturnValues(
			(int)($data[self::RETURN_REQUISITE_ID] ?? 0),
			(string)($data[self::RETURN_STATUS] ?? self::STATUS_ERROR),
			$errorMessage,
		);

		foreach ((array)($data[self::RETURN_WARNINGS] ?? []) as $warning)
		{
			if (is_string($warning) && $warning !== '')
			{
				$this->log($warning);
			}
		}

		if ($result->isSuccess() && (string)($data[self::RETURN_STATUS] ?? '') === CreateRequisiteOperation::STATUS_CREATED)
		{
			$this->log($this->getMessage(
				'CRM_CREATE_REQUISITE_ACTIVITY_SUCCESS_CREATED',
				'Requisite was created: #ID#',
				['#ID#' => (string)$this->RequisiteId],
			));
		}

		if (!$result->isSuccess())
		{
			if ($errorMessage === '')
			{
				$errorMessages = $result->getErrorMessages();
				$errorMessage = (string)end($errorMessages);
				$this->setProperty(self::RETURN_ERROR_MESSAGE, $errorMessage);
			}

			$this->logError($errorMessage);
		}
	}

	private function completeWithError(string $message): void
	{
		$this->setReturnValues(0, self::STATUS_ERROR, $message);
		$this->logError($message);
	}

	private function setReturnValues(int $requisiteId, string $status, string $errorMessage): void
	{
		$this->setProperty(self::RETURN_REQUISITE_ID, $requisiteId);
		$this->setProperty(self::RETURN_STATUS, $status);
		$this->setProperty(self::RETURN_ERROR_MESSAGE, $errorMessage);
	}

	private function getMessage(string $code, string $fallback, array $replace = []): string
	{
		return self::getStaticMessage($code, $fallback, $replace);
	}

	private static function getStaticMessage(string $code, string $fallback, array $replace = []): string
	{
		$message = Loc::getMessage($code, $replace);
		if (is_string($message) && $message !== '')
		{
			return $message;
		}

		return str_replace(array_keys($replace), array_values($replace), $fallback);
	}

	private static function getChangeRequisiteActivityFile(): string
	{
		return dirname(__DIR__) . '/crmchangerequisiteactivity/crmchangerequisiteactivity.php';
	}

	private static function getBankDetailNameTitle(): string
	{
		return self::getStaticMessage('CRM_CREATE_REQUISITE_ACTIVITY_FIELD_BANK_DETAIL_NAME', 'Bank detail name');
	}

	private static function resolveDirectClientTypeId(array $documentType): int
	{
		$documentTypeName = (string)($documentType[2] ?? '');
		$documentTypeId = \CCrmOwnerType::ResolveID($documentTypeName);

		return in_array($documentTypeId, [\CCrmOwnerType::Contact, \CCrmOwnerType::Company], true)
			? $documentTypeId
			: 0;
	}

	private static function getRequisitePresetOptions(): array
	{
		$presetsInfo = EntityPreset::getListForRequisiteEntityEditor();
		$options = [];
		foreach ($presetsInfo as $presetId => $info)
		{
			$options[$presetId] = $info['NAME'];
		}

		return $options;
	}

	private static function getCountryOptions(): array
	{
		$options = [];
		foreach (EntityPreset::getListForRequisiteEntityEditor() as $presetInfo)
		{
			$countryId = (int)($presetInfo['COUNTRY_ID'] ?? 0);
			if ($countryId > 0)
			{
				$options[$countryId] = GetCountryByID($countryId);
			}
		}

		return $options;
	}

	private static function isActiveRequisitePreset(int $requisitePresetId): bool
	{
		return isset(EntityPreset::getListForRequisiteEntityEditor()[$requisitePresetId]);
	}
}
