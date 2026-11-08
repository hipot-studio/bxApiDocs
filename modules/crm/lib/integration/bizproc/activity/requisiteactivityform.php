<?php

declare(strict_types=1);

namespace Bitrix\Crm\Integration\BizProc\Activity;

use Bitrix\Bizproc\Activity\PropertiesDialog;
use Bitrix\Bizproc\FieldType;
use Bitrix\Crm\Attribute\FieldAttributeManager;
use Bitrix\Crm\Attribute\FieldOrigin;
use Bitrix\Crm\EntityAddress;
use Bitrix\Crm\EntityBankDetail;
use Bitrix\Crm\EntityPreset;
use Bitrix\Crm\EntityRequisite;

final class RequisiteActivityForm
{
	public const REQUISITE_FIELDS = 'RequisiteFields';
	public const BANK_DETAIL_FIELDS = 'BankDetailFields';
	public const BANK_DETAIL_NAME = 'NAME';

	private const BANK_DETAIL_SYSTEM_FIELDS = [
		'ENTITY_TYPE_ID' => true,
		'ENTITY_ID' => true,
		'COUNTRY_ID' => true,
	];

	public static function configureDialog(
		PropertiesDialog $dialog,
		string $activityFile,
		bool $includeBankDetailName = false,
		string $bankDetailNameTitle = '',
		bool $storeAddressWithoutType = false,
	): PropertiesDialog
	{
		$currentPresetId = (int)$dialog->getCurrentValue('requisite_preset');
		$presetCountryId = $currentPresetId > 0
			? EntityRequisite::getSingleInstance()->getCountryIdByPresetId($currentPresetId)
			: 0;

		$runtimeData = [
			'PathToParentClassDir' => self::getGetRequisitesInfoActivityDir(),
			'PresetsInfo' => array_column(EntityPreset::getListForRequisiteEntityEditor(), 'COUNTRY_ID', 'ID'),
			'RequisiteFieldsMap' => array_merge(
				self::getRequisiteFieldsMap($presetCountryId),
				self::getUserFieldsMap(),
			),
			'BankDetailFieldsMap' => self::getBankDetailMap($includeBankDetailName, $bankDetailNameTitle),
			'AddressFieldsMap' => self::getAddressFieldsMap(),
			'PresetRequisiteFieldNames' => self::getPresetFieldNames(),
			'StoreAddressWithoutType' => $storeAddressWithoutType,
		];

		$dialog
			->setRuntimeData(array_merge($dialog->getRuntimeData(), $runtimeData))
			->setActivityFile($activityFile)
			->setCurrentValues(self::mergeAddressValuesWithRqValues($dialog->getCurrentValues()))
		;

		return $dialog;
	}

	public static function mergeAddressValuesWithRqValues(array $currentValues): array
	{
		$fieldsValues = $currentValues[self::REQUISITE_FIELDS] ?? $currentValues['FieldsValues'] ?? null;
		$fieldsValues = is_array($fieldsValues) ? $fieldsValues : [];
		$addressFields = EntityRequisite::ADDRESS;

		if (
			array_key_exists($addressFields, $fieldsValues)
			&& is_array($fieldsValues[$addressFields])
		)
		{
			$currentAddressFields = reset($fieldsValues[$addressFields]);
			if (is_array($currentAddressFields) && !\CBPHelper::isEmptyValue($currentAddressFields))
			{
				$fieldsValues = array_merge($fieldsValues, $currentAddressFields);
			}
		}

		unset($fieldsValues[$addressFields]);
		$currentValues['FieldsValues'] = $fieldsValues;

		return $currentValues;
	}

	public static function extractFieldsValues(
		array $documentType,
		\CBPDocumentService $documentService,
		array $currentValues,
		array &$errors,
		mixed $requisitePresetId,
		array $previousFieldsValues = [],
		bool $preserveUnavailableRqListFields = false,
		bool $includeBankDetailName = false,
		string $bankDetailNameTitle = '',
		bool $storeAddressWithoutType = false,
	): array
	{
		$requisiteFieldsMap = self::getRequisiteFieldsMap();
		if (is_numeric($requisitePresetId) && (int)$requisitePresetId > 0)
		{
			$countryId = EntityRequisite::getSingleInstance()->getCountryIdByPresetId((int)$requisitePresetId);
			$requisiteFieldsMap = self::getRequisiteFieldsMap($countryId);
		}

		$requisiteFieldsValues = array_merge(
			self::getValues(
				$documentType,
				$documentService,
				array_intersect_key($requisiteFieldsMap, $currentValues),
				$currentValues,
				$errors,
			),
			self::getValues(
				$documentType,
				$documentService,
				array_intersect_key(self::getUserFieldsMap(), $currentValues),
				$currentValues,
				$errors,
			),
		);

		if (
			$preserveUnavailableRqListFields
			&& (!is_numeric($requisitePresetId) || (int)$requisitePresetId <= 0)
		)
		{
			$requisiteFieldsValues = self::preserveUnavailableRqListFieldValues(
				$requisiteFieldsValues,
				$previousFieldsValues[self::REQUISITE_FIELDS] ?? [],
				$requisiteFieldsMap,
			);
		}

		$fieldsValues = [
			self::REQUISITE_FIELDS => $requisiteFieldsValues,
			self::BANK_DETAIL_FIELDS => self::getValues(
				$documentType,
				$documentService,
				array_intersect_key(
					self::getBankDetailMap($includeBankDetailName, $bankDetailNameTitle),
					$currentValues,
				),
				$currentValues,
				$errors,
			),
		];

		$addressValues = self::getValues(
			$documentType,
			$documentService,
			array_intersect_key(self::getAddressFieldsMap(), $currentValues),
			$currentValues,
			$errors,
		);

		if ($addressValues !== [])
		{
			$addressTypeId = (int)($currentValues['address_type_id'] ?? 0);
			if ($addressTypeId > 0 || $storeAddressWithoutType)
			{
				$fieldsValues[self::REQUISITE_FIELDS][EntityRequisite::ADDRESS] = [
					$addressTypeId => $addressValues,
				];
			}
		}

		return $fieldsValues;
	}

	public static function getValues(
		array $documentType,
		\CBPDocumentService $documentService,
		array $fieldsMap,
		array $currentValues,
		array &$errors,
	): array
	{
		$values = [];

		foreach ($fieldsMap as $propertyKey => $fieldProperties)
		{
			$field = $documentService->getFieldTypeObject($documentType, $fieldProperties);
			if (!$field)
			{
				continue;
			}

			$values[$propertyKey] = $field->extractValue(
				['Field' => $fieldProperties['FieldName']],
				$currentValues,
				$errors,
			);

			if ($values[$propertyKey] === null && array_key_exists('Getter', $fieldProperties))
			{
				$values[$propertyKey] = $fieldProperties['Getter'](
					new PropertiesDialog(__FILE__, ['documentType' => $documentType]),
					$fieldProperties,
					['Properties' => $currentValues],
					false,
				);
			}
		}

		return $values;
	}

	public static function normalizeFieldsValues(mixed $fieldsValues): array
	{
		if (!is_array($fieldsValues))
		{
			$fieldsValues = [];
		}

		if (!is_array($fieldsValues[self::REQUISITE_FIELDS] ?? null))
		{
			$fieldsValues[self::REQUISITE_FIELDS] = [];
		}

		if (!is_array($fieldsValues[self::BANK_DETAIL_FIELDS] ?? null))
		{
			$fieldsValues[self::BANK_DETAIL_FIELDS] = [];
		}

		if (array_key_exists(EntityRequisite::ADDRESS, $fieldsValues[self::REQUISITE_FIELDS]))
		{
			foreach ($fieldsValues[self::REQUISITE_FIELDS][EntityRequisite::ADDRESS] as &$addressFields)
			{
				if (!is_array($addressFields))
				{
					$addressFields = [];
				}
			}
			unset($addressFields);
		}

		return $fieldsValues;
	}

	public static function normalizeBooleanUserFieldValues(array $fieldValues, array $userFieldsMap): array
	{
		foreach ($userFieldsMap as $fieldName => $field)
		{
			if (
				($field['Type'] ?? null) === FieldType::BOOL
				&& array_key_exists($fieldName, $fieldValues)
			)
			{
				$fieldValues[$fieldName] = \CBPHelper::getBool($fieldValues[$fieldName]);
			}
		}

		return $fieldValues;
	}

	public static function getRequisiteFieldsMap(int $countryId = 0): array
	{
		if ($countryId === 0)
		{
			$countryId = EntityPreset::getCurrentCountryId();
		}

		$requisite = EntityRequisite::getSingleInstance();
		$rqFields = $requisite->getRqFields();
		$addressFieldIndex = array_search(EntityRequisite::ADDRESS, $rqFields, true);
		if ($addressFieldIndex !== false)
		{
			unset($rqFields[$addressFieldIndex]);
		}

		$map = self::combineFieldsMap(
			$rqFields,
			array_merge(
				$requisite->getFieldsTitles(GetCountryIdByCode('US')),
				$requisite->getFieldsTitles($countryId),
			),
		);
		unset($map['RQ_NAME']);

		foreach ($requisite->getRqListFields() as $fieldName)
		{
			if (!isset($map[$fieldName]))
			{
				continue;
			}

			$map[$fieldName]['Type'] = FieldType::SELECT;
			$map[$fieldName]['Options'] = array_column(
				$requisite->getRqListFieldItems($fieldName, $countryId),
				'NAME',
				'VALUE',
			);
		}

		return $map;
	}

	public static function getUserFieldsMap(): array
	{
		static $userFieldsMap = null;
		if (is_array($userFieldsMap))
		{
			return $userFieldsMap;
		}

		$userFieldNames = array_filter(
			EntityPreset::getSingleInstance()->getSettingsFieldsOfPresets(EntityPreset::Requisite),
			[self::class, 'isUserField'],
		);
		$userFields = \Bitrix\Main\UserFieldTable::getList([
			'select' => ['FIELD_NAME', 'USER_TYPE_ID'],
			'filter' => [
				'=ENTITY_ID' => EntityRequisite::getSingleInstance()->getUfId(),
				'@FIELD_NAME' => $userFieldNames,
			],
		])->fetchAll();
		$userFieldsMap = self::combineUserFieldsMap(
			$userFields,
			EntityRequisite::getSingleInstance()->getUserFieldsTitles(),
		);

		return $userFieldsMap;
	}

	/**
	 * A field left without a label in the current language keeps its own name as the label: dropping it
	 * would take the configured value of an already saved activity with it.
	 */
	public static function combineUserFieldsMap(array $userFields, array $userFieldTitles): array
	{
		$userFieldsMap = [];

		foreach ($userFields as $userField)
		{
			$fieldName = (string)$userField['FIELD_NAME'];
			$name = (string)($userFieldTitles[$fieldName] ?? '');
			if (\CBPHelper::isEmptyValue($name))
			{
				$name = $fieldName;
			}

			$userFieldsMap[$fieldName] = [
				'Name' => $name,
				'FieldName' => $fieldName,
				'Type' => $userField['USER_TYPE_ID'] === 'boolean' ? FieldType::BOOL : $userField['USER_TYPE_ID'],
			];
		}

		return $userFieldsMap;
	}

	public static function getBankDetailMap(bool $includeName = false, string $nameTitle = ''): array
	{
		$map = self::combineFieldsMap(
			EntityBankDetail::getSingleInstance()->getRqFields(),
			array_merge(
				EntityBankDetail::getSingleInstance()->getFieldsTitles(GetCountryIdByCode('US')),
				EntityBankDetail::getSingleInstance()->getFieldsTitles(),
			),
		);

		if ($includeName && !\CBPHelper::isEmptyValue($nameTitle))
		{
			$map = [
				self::BANK_DETAIL_NAME => [
					'Name' => $nameTitle,
					'FieldName' => self::BANK_DETAIL_NAME,
					'Type' => FieldType::STRING,
				],
			] + $map;
		}

		return $map;
	}

	public static function getAddressFieldsMap(): array
	{
		$addressFields = [];
		$addressFieldsInfo = EntityAddress::getFieldsInfo();
		$defaultFieldType = FieldType::STRING;

		foreach (EntityAddress::getLabels() as $fieldId => $fieldName)
		{
			if (
				$fieldId === 'ADDRESS'
				|| $fieldId === 'LOC_ADDR_ID'
				|| \CBPHelper::isEmptyValue($fieldName)
			)
			{
				continue;
			}

			$fieldType = $addressFieldsInfo[$fieldId]['TYPE'] ?? null;
			$addressFields[$fieldId] = [
				'Name' => $fieldName,
				'FieldName' => $fieldId,
				'Type' => $fieldType === null ? $defaultFieldType : self::resolveAddressField((string)$fieldType),
			];
		}

		return $addressFields;
	}

	public static function getPresetFieldNames(): array
	{
		static $presetFieldNames = null;
		if (isset($presetFieldNames))
		{
			return $presetFieldNames;
		}

		$presetFieldNames = [];
		$presets = EntityPreset::getSingleInstance()->getList([
			'select' => ['ID', 'SETTINGS'],
		])->fetchAll();
		foreach ($presets as $preset)
		{
			$presetFieldNames[(int)$preset['ID']] = array_column(
				$preset['SETTINGS']['FIELDS'] ?: [],
				'FIELD_NAME',
			);
		}

		return $presetFieldNames;
	}

	public static function getRequiredFieldNames(int $presetId): array
	{
		if ($presetId <= 0)
		{
			return [];
		}

		$requiredFields = FieldAttributeManager::getRequiredFields(
			\CCrmOwnerType::Requisite,
			0,
			['PRESET_ID' => $presetId],
			FieldOrigin::UNDEFINED,
			['PRESET_ID' => $presetId],
		);

		$fieldNames = array_merge(
			$requiredFields[FieldOrigin::SYSTEM] ?? [],
			$requiredFields[FieldOrigin::CUSTOM] ?? [],
		);
		$presetFieldNames = array_fill_keys(self::getPresetFieldNames()[$presetId] ?? [], true);

		return array_values(array_intersect($fieldNames, array_keys($presetFieldNames)));
	}

	public static function getFieldNameForError(string $fieldName, int $presetId): string
	{
		$countryId = EntityRequisite::getSingleInstance()->getCountryIdByPresetId($presetId);
		$map = array_merge(
			self::getRequisiteFieldsMap($countryId),
			self::getUserFieldsMap(),
			self::getAddressFieldsMap(),
			self::getBankDetailMap(),
		);

		return (string)($map[$fieldName]['Name'] ?? $fieldName);
	}

	public static function isFieldConfigured(array $fieldsValues, string $fieldName): bool
	{
		$fieldsValues = self::normalizeFieldsValues($fieldsValues);
		if ($fieldName === EntityRequisite::ADDRESS)
		{
			$addresses = $fieldsValues[self::REQUISITE_FIELDS][EntityRequisite::ADDRESS] ?? [];
			foreach ($addresses as $addressFields)
			{
				if (is_array($addressFields) && !\CBPHelper::isEmptyValue($addressFields))
				{
					return true;
				}
			}

			return false;
		}

		return
			array_key_exists($fieldName, $fieldsValues[self::REQUISITE_FIELDS])
			&& !\CBPHelper::isEmptyValue($fieldsValues[self::REQUISITE_FIELDS][$fieldName]);
	}

	public static function hasBankDetailFieldsWithoutName(array $bankDetailFields): bool
	{
		foreach ($bankDetailFields as $fieldName => $value)
		{
			if (
				$fieldName === self::BANK_DETAIL_NAME
				|| isset(self::BANK_DETAIL_SYSTEM_FIELDS[$fieldName])
			)
			{
				continue;
			}

			if (!\CBPHelper::isEmptyValue($value))
			{
				return true;
			}
		}

		return false;
	}

	public static function isValueVariable(array $fieldProperties, mixed $fieldValue): bool
	{
		if (is_array($fieldValue) || is_object($fieldValue))
		{
			return true;
		}

		if (($fieldProperties['Type'] ?? null) === FieldType::SELECT)
		{
			return !array_key_exists($fieldValue, (array)($fieldProperties['Options'] ?? []));
		}

		return \CBPActivity::isExpression($fieldValue);
	}

	private static function preserveUnavailableRqListFieldValues(
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

	private static function combineFieldsMap(
		array $fieldNames,
		array $fieldTitles,
		string $fieldType = FieldType::STRING,
	): array
	{
		$fieldsMap = [];

		foreach ($fieldNames as $name)
		{
			if (!\CBPHelper::isEmptyValue($fieldTitles[$name] ?? null))
			{
				$fieldsMap[$name] = [
					'Name' => $fieldTitles[$name],
					'FieldName' => $name,
					'Type' => $fieldType,
				];
			}
		}

		return $fieldsMap;
	}

	private static function resolveAddressField(string $type): string
	{
		return match ($type)
		{
			'integer' => FieldType::INT,
			'boolean' => FieldType::BOOL,
			default => FieldType::STRING,
		};
	}

	private static function isUserField(mixed $fieldId): bool
	{
		return str_starts_with((string)$fieldId, 'UF_');
	}

	private static function getGetRequisitesInfoActivityDir(): string
	{
		if (class_exists(\CBPCrmGetRequisitesInfoActivity::class))
		{
			$fileName = (new \ReflectionClass(\CBPCrmGetRequisitesInfoActivity::class))->getFileName();
			if (is_string($fileName) && $fileName !== '')
			{
				return dirname($fileName);
			}
		}

		return dirname(__DIR__, 4) . '/install/activities/bitrix/crmgetrequisitesinfoactivity';
	}
}
