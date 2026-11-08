<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED!==true)
{
	die();
}

use Bitrix\Bizproc\Activity\Mixins\ChecksResolvedTargetAccessTrait;
use Bitrix\Crm\EntityBankDetail;
use Bitrix\Crm\EntityPreset;
use Bitrix\Crm\EntityRequisite;
use Bitrix\Crm\Field;
use Bitrix\Crm\Integration\BizProc\Activity\RequisiteActivityForm;
use Bitrix\Crm\Integration\Analytics\Dictionary;
use Bitrix\Main\Localization\Loc;

$runtime = CBPRuntime::GetRuntime();
$runtime->IncludeActivityFile('CrmGetRequisitesInfoActivity');

class CBPCrmChangeRequisiteActivity extends CBPCrmGetRequisitesInfoActivity
{
	use ChecksResolvedTargetAccessTrait;

	protected ?int $currentPresetId = null;
	protected ?int $currentPresetCountryId = null;

	public function __construct($name)
	{
		parent::__construct($name);

		$this->arProperties['FieldsValues'] = null;
	}

	public function Execute()
	{
		if (!CModule::IncludeModule('crm'))
		{
			return CBPActivityExecutionStatus::Closed;
		}

		$this->resolveTargetDocumentId();
		$documentType = $this->getDocumentType();

		[$this->CrmEntityType, $this->CrmEntityId] = $this->defineCrmEntityWithRequisites();
		$this->logRequisiteProperties();

		$executionStatus = $this->assertProperties();
		if ($executionStatus !== CBPActivityExecutionStatus::Executing)
		{
			return $executionStatus;
		}

		$targetDocumentId = CCrmBizProcHelper::ResolveDocumentId($this->CrmEntityType, $this->CrmEntityId);
		if (is_array($targetDocumentId) && !$this->canUpdateResolvedTarget($targetDocumentId))
		{
			$this->logResolvedTargetAccessDenied();

			return CBPActivityExecutionStatus::Closed;
		}

		$fieldsValues = self::normalizeFieldsValues($this->FieldsValues);
		$fieldsValues['RequisiteFields'] = $this->filterPresetRequisiteFields($fieldsValues['RequisiteFields']);

		$this->logRequisiteValues($fieldsValues);

		$requisiteSettings = EntityRequisite::getSingleInstance()->loadSettings(
			$this->CrmEntityType,
			$this->CrmEntityId,
		);

		$requisiteId = $this->getRequisiteId($requisiteSettings);
		$bankDetailId = $this->getBankDetailId($requisiteSettings, $requisiteId, $this->getRequisitePresetId());

		$this->updateRequisite($requisiteId, $bankDetailId, $fieldsValues);

		\CCrmBizProcHelper::sendOperationsAnalytics(
			Dictionary::EVENT_ENTITY_EDIT,
			$this,
			$documentType[2] ?? '',
		);

		return CBPActivityExecutionStatus::Closed;
	}

	protected function logRequisiteValues(array $requisiteValues): void
	{
		if (!$this->workflow->isDebug())
		{
			return;
		}

		$requisiteFieldValues = $requisiteValues['RequisiteFields'];
		$addressFieldValues = $requisiteValues['RequisiteFields'][EntityRequisite::ADDRESS][$this->AddressTypeId] ?? [];
		unset($requisiteFieldValues['RequisiteFields'][EntityRequisite::ADDRESS]);
		$countryId = $this->getCurrentPresetCountryId();

		$debugValues = array_merge($requisiteFieldValues, $addressFieldValues, $requisiteValues['BankDetailFields']);

		$map = array_merge(
			static::getRequisiteFieldsMap($countryId),
			static::getUserFieldsMap(),
			static::getAddressFieldsMap(),
			static::getBankDetailMap(),
		);

		$this->writeDebugInfo($this->getDebugInfo($debugValues, array_intersect_key($map, $debugValues)));
	}

	protected static function getPropertiesMap(array $documentType, array $context = []): array
	{
		return parent::getPropertiesDialogMap();
	}

	protected function filterPresetRequisiteFields(array $requisiteFieldsValues)
	{
		$requisiteFieldsIds = EntityRequisite::getSingleInstance()->getRqFields();
		$requisitePresetId = $this->getRequisitePresetId();
		$preset = EntityPreset::getSingleInstance()->getById($requisitePresetId) ?: [];
		$this->currentPresetId = $requisitePresetId;
		$this->currentPresetCountryId = isset($preset['COUNTRY_ID']) ? (int)$preset['COUNTRY_ID'] : 0;

		$presetFields = $preset['SETTINGS']['FIELDS'] ?? [];
		$presetFieldsIds = array_column(
			$presetFields,
			null,
			'FIELD_NAME',
		);

		foreach ($requisiteFieldsIds as $fieldId)
		{
			if (isset($requisiteFieldsValues[$fieldId]) && array_key_exists($fieldId, $presetFieldsIds) === false)
			{
				unset($requisiteFieldsValues[$fieldId]);
			}
		}

		return $requisiteFieldsValues;
	}

	protected function getRequisiteId(array $requisiteSettings): int
	{
		return (int)$this->getRequisiteBySettings($requisiteSettings, ['ID'])['ID'];
	}

	protected function getBankDetailId(array $requisiteSettings, int $requisiteId, int $presetId): int
	{
		return (int)$this->getBankDetailBySettings(
			$requisiteSettings,
			['ID' => $requisiteId, 'PRESET_ID' => $presetId],
			['ID'],
		)['ID'];
	}

	protected function updateRequisite(int $requisiteId, int $bankDetailId, array $fieldsValues)
	{
		$options = ['DISABLE_REQUIRED_USER_FIELD_CHECK' => true];
		$requisite = EntityRequisite::getSingleInstance();
		$bankDetail = EntityBankDetail::getSingleInstance();

		$fieldsValues = $this->prepareRequisiteFieldValues($requisiteId, $fieldsValues);

		if ($fieldsValues['RequisiteFields'])
		{
		 	$res = $requisite->checkBeforeUpdate($requisiteId, $fieldsValues['RequisiteFields'], $options);
		 	if ($res->isSuccess())
			{
				$requisite->update($requisiteId, $fieldsValues['RequisiteFields'], $options);
			}
		 	else
			{
				$errorMessages = $res->getErrorMessages();
				$this->WriteToTrackingService(end($errorMessages), 0, CBPTrackingType::Error);
			}
		}
		if ($fieldsValues['BankDetailFields'])
		{
			$presetId = $requisite->getPresetIdFromFields($fieldsValues['RequisiteFields']);
			if ($presetId > 0)
			{
				$options['FIELD_CHECK_OPTIONS'] = ['PRESET_ID' => $presetId];
			}
			$res = $bankDetail->checkBeforeUpdate($bankDetailId, $fieldsValues['BankDetailFields'], $options);
			if ($res->isSuccess())
			{
				$bankDetail->update($bankDetailId, $fieldsValues['BankDetailFields'], $options);
			}
			else
			{
				$errorMessages = $res->getErrorMessages();
				$this->WriteToTrackingService(end($errorMessages), 0, CBPTrackingType::Error);
			}
		}
	}

	protected function prepareRequisiteFieldValues(int $requisiteId, array $fieldsValues): array
	{
		$addressFields = EntityRequisite::ADDRESS;
		$addressTypeId = $this->AddressTypeId;

		foreach ($fieldsValues as $requisiteSectionName => $requisiteSectionFields)
		{
			foreach ($requisiteSectionFields as $fieldId => $value)
			{
				$fieldsValues[$requisiteSectionName][$fieldId] = $this->parseValue($value);
			}
		}

		if ($this->hasPresetRequisiteFieldsToFilter($fieldsValues['RequisiteFields']))
		{
			$fieldsValues['RequisiteFields'] = $this->filterInvalidStringFieldValues(
				$fieldsValues['RequisiteFields'],
				$this->getCurrentPresetRequisiteFieldsMap(),
			);
		}
		$fieldsValues['BankDetailFields'] = $this->filterInvalidStringFieldValues(
			$fieldsValues['BankDetailFields'],
			static::getBankDetailMap(),
		);
		$fieldsValues['RequisiteFields'] = $this->prepareUserFieldValues($fieldsValues['RequisiteFields']);

		if (array_key_exists(EntityRequisite::ADDRESS, $fieldsValues['RequisiteFields']))
		{
			$fieldsValues['RequisiteFields'][$addressFields][$addressTypeId] = $this->filterInvalidStringFieldValues(
				$fieldsValues['RequisiteFields'][$addressFields][$addressTypeId],
				static::getAddressFieldsMap(),
			);
			$fieldsValues['RequisiteFields'][$addressFields][$addressTypeId] = $this->prepareAddressFieldValues(
				$requisiteId,
				$fieldsValues['RequisiteFields'][$addressFields][$addressTypeId],
			);
		}

		return $fieldsValues;
	}

	protected function prepareUserFieldValues($requisiteFieldValues)
	{
		foreach (self::getUserFieldsMap() as $fieldId => $fieldProperties)
		{
			if ($fieldProperties['Type'] === 'bool' && array_key_exists($fieldId, $requisiteFieldValues))
			{
				$requisiteFieldValues[$fieldId] = CBPHelper::getBool($requisiteFieldValues[$fieldId]);
			}
		}

		return $requisiteFieldValues;
	}

	protected function filterInvalidStringFieldValues(array $fieldValues, array $fieldsMap): array
	{
		foreach ($fieldValues as $fieldId => $value)
		{
			if (($fieldsMap[$fieldId]['Type'] ?? null) !== \Bitrix\Bizproc\FieldType::STRING)
			{
				continue;
			}

			if (!is_array($value) && !is_object($value))
			{
				continue;
			}
			if ($value instanceof \Stringable)
			{
				$fieldValues[$fieldId] = (string)$value;

				continue;
			}

			$this->writeInvalidStringFieldTrackingError($fieldId, $fieldsMap[$fieldId]);
			unset($fieldValues[$fieldId]);
		}

		return $fieldValues;
	}

	protected function hasPresetRequisiteFieldsToFilter(array $requisiteFieldsValues): bool
	{
		$requisiteFieldsIds = array_fill_keys(EntityRequisite::getSingleInstance()->getRqFields(), true);
		unset($requisiteFieldsIds[EntityRequisite::ADDRESS]);

		return array_intersect_key($requisiteFieldsValues, $requisiteFieldsIds) !== [];
	}

	protected function getCurrentPresetRequisiteFieldsMap(): array
	{
		$requisitePresetId = $this->getRequisitePresetId();
		if ($requisitePresetId <= 0)
		{
			return static::getRequisiteFieldsMap();
		}

		return static::getRequisiteFieldsMap(
			$this->getCurrentPresetCountryId(),
		);
	}

	protected function getCurrentPresetCountryId(): int
	{
		$requisitePresetId = $this->getRequisitePresetId();
		if ($requisitePresetId <= 0)
		{
			return 0;
		}

		if ($this->currentPresetId !== $requisitePresetId || $this->currentPresetCountryId === null)
		{
			$this->currentPresetId = $requisitePresetId;
			$this->currentPresetCountryId = EntityRequisite::getSingleInstance()->getCountryIdByPresetId($requisitePresetId);
		}

		return $this->currentPresetCountryId;
	}

	protected function writeInvalidStringFieldTrackingError(string $fieldId, array $fieldProperties): void
	{
		$fieldName = (string)($fieldProperties['Name'] ?? $fieldId);
		Loc::loadMessages($_SERVER['DOCUMENT_ROOT'] . BX_ROOT . '/modules/crm/lib/field.php');
		$message = Loc::getMessage(Field::MESSAGE_FIELD_VALUE_NOT_VALID, ['#FIELD#' => $fieldName]);
		if (!is_string($message) || $message === '')
		{
			$message = 'Invalid field value: ' . $fieldName;
		}

		$this->WriteToTrackingService($message, 0, CBPTrackingType::Error);
	}

	protected function prepareAddressFieldValues(int $requisiteId, array $rawAddressFields) : array
	{
		$addressFields = EntityRequisite::getAddresses($requisiteId)[$this->AddressTypeId] ?: [];
		$addressFields = array_merge($addressFields, $rawAddressFields);

		if (\Bitrix\Crm\EntityAddress::isLocationModuleIncluded() && isset($addressFields['LOC_ADDR_ID']))
		{
			$locationAddress = \Bitrix\Crm\EntityAddress::makeLocationAddressByFields($addressFields);

			$addressFields = isset($locationAddress) ? ['LOC_ADDR' => $locationAddress] : [];
		}

		return $addressFields;
	}

	public static function GetPropertiesDialog($documentType, $activityName, $workflowTemplate, $workflowParameters, $workflowVariables, $currentValues = null, $formName = '', $popupWindow = null, $siteId = '')
	{
		$dialog = parent::GetPropertiesDialog(...func_get_args());

		return RequisiteActivityForm::configureDialog($dialog, __FILE__);
	}

	protected static function mergeAddressValuesWithRqValues(array $currentValues): array
	{
		return RequisiteActivityForm::mergeAddressValuesWithRqValues($currentValues);
	}

	public static function GetPropertiesDialogValues($documentType, $activityName, &$workflowTemplate, &$workflowParameters, &$workflowVariables, $currentValues, &$errors)
	{
		$runtime = CBPRuntime::GetRuntime();
		$runtime->StartRuntime();

		$documentService = $runtime->GetService('DocumentService');

		$properties = RequisiteActivityForm::getValues(
			$documentType, $documentService, parent::getPropertiesDialogMap(),
			$currentValues, $errors,
		);
		$requisitePresetId = $properties['RequisitePresetId'] ?? null;

		$currentActivity = &CBPWorkflowTemplateLoader::FindActivityByName($workflowTemplate, $activityName);
		$previousFieldsValues = RequisiteActivityForm::normalizeFieldsValues(
			array_merge(
				[
					'RequisiteFields' => [],
					'BankDetailFields' => [],
				],
				(array)($currentActivity['Properties']['FieldsValues'] ?? []),
			),
		);
		$properties['FieldsValues'] = RequisiteActivityForm::extractFieldsValues(
			$documentType,
			$documentService,
			$currentValues,
			$errors,
			$requisitePresetId,
			$previousFieldsValues,
			true,
		);

		$errors = self::ValidateProperties(
			$properties,
			new CBPWorkflowTemplateUser(CBPWorkflowTemplateUser::CurrentUser),
		);

		$isCorrect = (count($errors) <= 0);
		if($isCorrect)
		{
			$currentActivity['Properties'] = $properties;
		}

		return $isCorrect;
	}

	protected static function preserveUnavailableRqListFieldValues(
		array $currentValues,
		array $previousValues,
		array $availableFieldsMap,
	): array
	{
		$rqListFieldsMap = array_fill_keys(
			EntityRequisite::getSingleInstance()->getRqListFields(),
			true,
		);
		$unavailableRqListFieldsMap = array_diff_key($rqListFieldsMap, $availableFieldsMap);

		return array_merge(
			array_intersect_key($previousValues, $unavailableRqListFieldsMap),
			$currentValues,
		);
	}

	protected static function getRequisiteFieldsMap(int $countryId = 0): array
	{
		return RequisiteActivityForm::getRequisiteFieldsMap($countryId);
	}

	protected static function getUserFieldsMap(): array
	{
		return RequisiteActivityForm::getUserFieldsMap();
	}

	protected static function getBankDetailMap(): array
	{
		return RequisiteActivityForm::getBankDetailMap();
	}

	protected static function getAddressFieldsMap(): array
	{
		return RequisiteActivityForm::getAddressFieldsMap();
	}

	public static function ValidateProperties($testProperties = [], ?CBPWorkflowTemplateUser $user = null)
	{
		$testProperties['FieldsValues'] = self::normalizeFieldsValues($testProperties['FieldsValues'] ?? []);
		$errors = parent::ValidateProperties($testProperties, $user);

		$isPropertySpecified = false;
		foreach ($testProperties['FieldsValues'] as $requisiteTypeFields)
		{
			foreach ($requisiteTypeFields as $fieldType => $fieldsValues)
			{
				if ($fieldType === EntityRequisite::ADDRESS)
				{
					$fieldsValues = reset($fieldsValues);
				}
				if (!CBPHelper::isEmptyValue($fieldsValues)) {
					$isPropertySpecified = true;

					break;
				}
			}
		}

		if($isPropertySpecified === false)
		{
			$errors[] = [
				'code' => 'NotExist',
				'parameter' => 'FieldValue',
				'message' => GetMessage('CRM_CRA_EMPTY_FIELDS'),
			];
		}

		return $errors;
	}

	protected static function normalizeFieldsValues(mixed $fieldsValues): array
	{
		return RequisiteActivityForm::normalizeFieldsValues($fieldsValues);
	}

	protected static function getPropertiesDialogMap(): array
	{
		return array_merge(parent::getPropertiesDialogMap(), [
			'FieldsValues' => [
				'FieldName' => 'FieldsValues',
				'Getter' => function ($dialog, $property, $currentActivity, $compatible)
				{
					$requisiteFields = $currentActivity['Properties']['FieldsValues']['RequisiteFields'];
					$bankDetailFields = $currentActivity['Properties']['FieldsValues']['BankDetailFields'];

					return array_merge(
						is_array($requisiteFields) ? $requisiteFields : [],
						is_array($bankDetailFields) ? $bankDetailFields : [],
					);
				},
			],
		]);
	}
}
