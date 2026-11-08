<?php
namespace Bitrix\Crm\Synchronization;
use Bitrix\Crm\Entity\Traits\VisibilityConfig;
use Bitrix\Crm\Integration\Analytics\Builder\Userfield\Context\ConvertContext;
use Bitrix\Crm\Model;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\Factory;
use Bitrix\Crm\UserField\Visibility\VisibilityManager;
use Bitrix\Main;
use Bitrix\Main\Type\DateTime;
use Bitrix\Crm\UserField\UserFieldHistory;
use Bitrix\Main\UserField\Access\Permission\PermissionDictionary;

class UserFieldSynchronizer
{
	use VisibilityConfig;

	private const COMPATIBILITY_HISTORY_VERSION = 3;
	private const ENUMERATION_VALUE_HASH_CHUNK_SIZE = 100;
	private const IBLOCK_BINDING_USER_FIELD_TYPES = [
		'iblock_element' => true,
		'iblock_section' => true,
	];
	private const VALUE_VALIDATED_RECYCLING_TYPES = [
		'boolean' => true,
		'date' => true,
		'datetime' => true,
		'double' => true,
		'enumeration' => true,
		'file' => true,
		'integer' => true,
		'string' => true,
		'string_formatted' => true,
		'url' => true,
	];

	/** @var DateTime[]|null $items*/
	private static $timestamps = null;
	/** @var array|null $history*/
	private static $history = null;

	/** @var array|null $existedFieldNameMap*/
	public static $existedFieldNameMap = array();
	private static array $enumerationValueHashes = [];
	private static array $enumerationValues = [];

	/**
	* Check if destination type fields need for synchronization with source fields.
	* Matches are searched by comparing field compatibility.
	* @static
	* @param int $srcEntityTypeID Source Entity Type ID
	* @param int $dstEntityTypeID Destination Entity Type ID
	* @param string $languageID Language
	* @param array $options Operation options
	* @return bool
	*/
	public static function needForSynchronization($srcEntityTypeID, $dstEntityTypeID, $languageID = '', array $options = array())
	{
		$isRecycling = (isset($options['IS_RECYCLING']) && $options['IS_RECYCLING'] === true);
		$fieldNames = isset($options['FIELD_NAME']) ? (array)$options['FIELD_NAME'] : null;

		$fieldsToCreate = self::getSynchronizationFields(
			$srcEntityTypeID,
			$dstEntityTypeID,
			$languageID,
			false,
			$isRecycling,
			false,
			false,
			$fieldNames
		);
		if(!empty($fieldsToCreate))
		{
			return true;
		}

		if(isset($options['ENABLE_TRIM']) && $options['ENABLE_TRIM'] === true)
		{
			$fieldsToDelete = self::getSynchronizationFields(
				$dstEntityTypeID,
				$srcEntityTypeID,
				$languageID,
				false,
				$isRecycling,
				true,
				$isRecycling,
				null,
				false
			);
			if(!empty($fieldsToDelete))
			{
				return true;
			}
		}

		return false;
	}
	/**
	* Prepare synchronization field list.
	* @static
	* @param int $srcEntityTypeID Source Entity Type ID
	* @param int $dstEntityTypeID Destination Entity Type ID
	* @param string $languageID Language
	* @return array
	*/
	public static function getSynchronizationFields(
		$srcEntityTypeID,
		$dstEntityTypeID,
		$languageID = '',
		$forced = false,
		$isRecycling = false,
		bool $allowRecyclingCompatibility = false,
		bool $matchByBasicAttributesOnly = false,
		?array $fieldNames = null,
		bool $skipUnsynchronizableFields = true
	)
	{
		if(!is_int($srcEntityTypeID))
		{
			$srcEntityTypeID = (int)$srcEntityTypeID;
		}


		if(!is_int($dstEntityTypeID))
		{
			$dstEntityTypeID = (int)$dstEntityTypeID;
		}

		if($srcEntityTypeID === $dstEntityTypeID
			|| !\CCrmOwnerType::IsDefined($srcEntityTypeID)
			|| !\CCrmOwnerType::IsDefined($dstEntityTypeID)
		)
		{
			return array();
		}

		$historyItem = self::getHistoryItem($srcEntityTypeID, $dstEntityTypeID);
		if($historyItem !== null && !$forced && self::isHistoryItemCompatible($historyItem))
		{
			$srcLastChanged = UserFieldHistory::getLastChangeTime($srcEntityTypeID);
			$dstLastChanged = UserFieldHistory::getLastChangeTime($dstEntityTypeID);

			$lastChanged = null;
			if($srcLastChanged !== null && $dstLastChanged !== null)
			{
				$lastChanged = $srcLastChanged->getTimestamp() > $dstLastChanged->getTimestamp()
					? $srcLastChanged : $dstLastChanged;
			}
			elseif($srcLastChanged !== null || $dstLastChanged !== null)
			{
				$lastChanged = $srcLastChanged !== null
					? $srcLastChanged : $dstLastChanged;
			}

			$lastChangeTimestamp = $lastChanged !== null ? $lastChanged->getTimestamp() : 0;
			/** @var DateTime $sync */
			$sync = isset($historyItem['sync']) ? $historyItem['sync'] : null;
			if($sync !== null && $sync->getTimestamp() > $lastChangeTimestamp)
			{
				return array();
			}

			/** @var DateTime $check */
			$check = isset($historyItem['check']) ? $historyItem['check'] : null;
			$required = isset($historyItem['required']) ? $historyItem['required'] : null;
			if($check !== null && $check->getTimestamp() > $lastChangeTimestamp && $required === false)
			{
				return array();
			}
		}

		if(!is_string($languageID) || $languageID === '')
		{
			$languageID = LANGUAGE_ID;
		}

		$srcUfEntityID = \CCrmOwnerType::ResolveUserFieldEntityID($srcEntityTypeID);
		$dstUfEntityID = \CCrmOwnerType::ResolveUserFieldEntityID($dstEntityTypeID);

		if($srcUfEntityID === '' || $dstUfEntityID === '')
		{
			return array();
		}

		/** @var \CUserTypeManager $USER_FIELD_MANAGER */
		global $USER_FIELD_MANAGER;

		$dstFields = $USER_FIELD_MANAGER->GetUserFields($dstUfEntityID, 0, $languageID);
		$map = self::prepareCompatibilityFieldMap($dstFields);

		self::$existedFieldNameMap = array();
		$results = array();

		$srcFields = $USER_FIELD_MANAGER->GetUserFields($srcUfEntityID, 0, $languageID);
		if (!$isRecycling)
		{
			$srcFields = VisibilityManager::getVisibleUserFields($srcFields);
		}

		if($fieldNames !== null)
		{
			$srcFields = self::filterFieldsByName($srcFields, $fieldNames);
		}
		self::preloadEnumerationValueHashes($srcFields, $map, true);

		foreach($srcFields as $field)
		{
			$label = self::getFieldComplianceCode($field);
			if($label === '')
			{
				continue;
			}

			$typeID = $field['USER_TYPE_ID'];
			if(!self::isUserFieldTypeSupported($typeID))
			{
				continue;
			}
			$dstFieldName = self::findCompatibleFieldName(
				$field,
				$map,
				[],
				false,
				$allowRecyclingCompatibility,
				false,
				$matchByBasicAttributesOnly
			);
			if($dstFieldName === null)
			{
				if($skipUnsynchronizableFields && !self::isUserFieldSynchronizable($field))
				{
					continue;
				}

				$results[] = $field;
			}
			else
			{
				self::$existedFieldNameMap[$field['FIELD_NAME']] = $dstFieldName;
			}
		}

		if($historyItem === null)
		{
			$historyItem = array('sync' => null);
		}

		$historyItem['check'] = new DateTime();
		$historyItem['required'] = !empty($results);
		$historyItem['version'] = self::COMPATIBILITY_HISTORY_VERSION;
		self::setHistoryItem($srcEntityTypeID, $dstEntityTypeID, $historyItem);

		return array_values($results);
	}

	public static function isUserFieldTypeSupported($userFieldTypeID)
	{
		return $userFieldTypeID !== 'resourcebooking';
	}

	private static function isUserFieldSynchronizable(array $field): bool
	{
		$typeId = $field['USER_TYPE_ID'] ?? '';
		if(!isset(self::IBLOCK_BINDING_USER_FIELD_TYPES[$typeId]))
		{
			return true;
		}

		$settings = self::prepareUserFieldSettings($field['SETTINGS'] ?? []);

		return (int)($settings['IBLOCK_ID'] ?? 0) > 0;
	}

	private static function prepareUserFieldSettings(mixed $settings): array
	{
		if(is_array($settings))
		{
			return $settings;
		}

		if(!is_string($settings))
		{
			return [];
		}

		$unserializedSettings = @unserialize($settings, ['allowed_classes' => false]);

		return is_array($unserializedSettings) ? $unserializedSettings : [];
	}

	/**
	 * Synchronize source type fields with destination type fields.
	 * Matches are searched by comparing field compatibility.
	 * If a source field is not found in the destination type, it will be created there.
	 * @static
	 * @param int $srcEntityTypeID Source Entity Type ID
	 * @param int $dstEntityTypeID Destination Entity Type ID
	 * @param string $languageID Language
	 * @param array $filter Filter
	 * @param array $options Operation options
	 * @return array $synchronizedFieldNameMap
	 * @throws Main\ObjectException
	 * @throws UserFieldSynchronizationException
	 */
	public static function synchronize($srcEntityTypeID, $dstEntityTypeID, $languageID = '', $filter = array(), array $options = array())
	{
		/** @var \CMain $APPLICATION */
		global $APPLICATION;

		$synchronizedFieldNameMap = array();
		$entity = new \CUserTypeEntity();

		$isRecycling = (isset($options['IS_RECYCLING']) && $options['IS_RECYCLING'] === true);

		if(isset($options['ENABLE_TRIM']) && $options['ENABLE_TRIM'] === true)
		{
			$fieldsToDelete = self::getSynchronizationFields(
				$dstEntityTypeID,
				$srcEntityTypeID,
				$languageID,
				true,
				$isRecycling,
				true,
				$isRecycling,
				null,
				false
			);
			foreach($fieldsToDelete as $field)
			{
				if(self::isUserFieldTypeSupported($field['USER_TYPE_ID']))
				{
					$entity->Delete($field['ID']);
				}
			}
		}

		$entityID = \CCrmOwnerType::ResolveUserFieldEntityID($dstEntityTypeID);
		if(isset($filter['FIELD_NAME']))
		{
			$filter['FIELD_NAME'] = is_array($filter['FIELD_NAME'])
				? $filter['FIELD_NAME']
				: array($filter['FIELD_NAME'])
			;
		}

		$fieldsToCreateDraft = self::getSynchronizationFields(
			$srcEntityTypeID,
			$dstEntityTypeID,
			$languageID,
			true,
			$isRecycling,
			false,
			false,
			$filter['FIELD_NAME'] ?? null
		);
		if(isset($filter['FIELD_NAME']))
		{
			$fieldsToCreateDraft = self::filterFieldsByName($fieldsToCreateDraft, $filter['FIELD_NAME']);
		}

		$labelMap = [];
		foreach ($fieldsToCreateDraft as $index => $field)
		{
			$compatibilityKey = self::getCompatibilityKey($field);
			if($compatibilityKey !== '' && !isset($labelMap[$compatibilityKey]))
			{
				$labelMap[$compatibilityKey] = $index;
			}
		}
		$fieldsToCreate = [];
		foreach ($labelMap as $index)
		{
			$fieldsToCreate[] = $fieldsToCreateDraft[$index];
		}
		unset($fieldsToCreateDraft);

		if(isset($filter['FIELD_NAME']))
		{
			foreach(self::$existedFieldNameMap as $existedSrcFieldName => $existedDstFieldName)
			{
				if(!in_array($existedSrcFieldName, $filter['FIELD_NAME']))
				{
					unset(self::$existedFieldNameMap[$existedSrcFieldName]);
				}
			}
		}

		foreach($fieldsToCreate as $field)
		{
			$srcField = $entity->GetByID($field['ID']);
			if(!is_array($srcField))
			{
				continue;
			}

			$typeID = $srcField['USER_TYPE_ID'];
			if(!self::isUserFieldTypeSupported($typeID))
			{
				continue;
			}
			if(!self::isUserFieldSynchronizable($srcField))
			{
				continue;
			}

			if(isset($filter['FIELD_NAME']))
			{
				if(!in_array($srcField['FIELD_NAME'], $filter['FIELD_NAME']))
				{
					continue;
				}
			}

			do
			{
				$fieldName = 'UF_CRM_'.mb_strtoupper(uniqid());
				$dbResult = $entity->GetList(
					array(),
					array('ENTITY_ID' => $entityID, 'FIELD_NAME' => $fieldName)
				);
			}
			while(is_array($dbResult->Fetch()));

			$dstField = array(
				'FIELD_NAME' => $fieldName,
				'ENTITY_ID' => $entityID,
				'USER_TYPE_ID' => $typeID,
				'SORT' => $srcField['SORT'] ?? 100,
				'MULTIPLE' => $srcField['MULTIPLE'] ?? 'N',
				'MANDATORY' => $srcField['MANDATORY'] ?? 'N',
				'SHOW_FILTER' => $srcField['SHOW_FILTER'] ?? 'N',
				'SHOW_IN_LIST' => $srcField['SHOW_IN_LIST'] ?? 'N'
			);

			if (isset($srcField['SETTINGS']))
			{
				$dstField['SETTINGS'] = $srcField['SETTINGS'];
			}

			if (isset($srcField['EDIT_FORM_LABEL']))
			{
				$dstField['EDIT_FORM_LABEL'] = $srcField['EDIT_FORM_LABEL'];
			}

			if (isset($srcField['LIST_COLUMN_LABEL']))
			{
				$dstField['LIST_COLUMN_LABEL'] = $srcField['LIST_COLUMN_LABEL'];
			}

			if (isset($srcField['LIST_FILTER_LABEL']))
			{
				$dstField['LIST_FILTER_LABEL'] = $srcField['LIST_FILTER_LABEL'];
			}

			if (isset($srcField['HELP_MESSAGE']))
			{
				$dstField['HELP_MESSAGE'] = $srcField['HELP_MESSAGE'];
			}

			$dstField['ANALYTICS'] = (new ConvertContext())
				->setSourceEntityTypeId($srcEntityTypeID)
			;

			$ID = $entity->Add($dstField);
			if($ID === false)
			{
				$ex = $APPLICATION->GetException();
				if(!($ex instanceof \CApplicationException))
				{
					$ex = null;
				}

				throw new UserFieldSynchronizationException(
					$dstField,
					$ex,
					UserFieldSynchronizationException::CREATE_FAILED,
					__FILE__,
					__LINE__
				);
			}

			//region UserField visible access settings
			$visibilityConfig = self::getInstance()
				->prepareEntityFieldvisibilityConfigs($srcEntityTypeID);
			if (isset($visibilityConfig[$srcField['FIELD_NAME']]))
			{
				$accessCodesKeys = array_keys($visibilityConfig[$srcField['FIELD_NAME']]['accessCodes'] ?? []);
				$accessCodes = array_map(
					static function($accessCode){
						return ['ID' => $accessCode];
					}, $accessCodesKeys
				);
				VisibilityManager::saveEntityConfiguration(
					$accessCodes,
					$dstField['FIELD_NAME'],
					$dstEntityTypeID,
					PermissionDictionary::USER_FIELD_VIEW
					);
			}
			// endregion

			if($typeID === 'enumeration')
			{
				if (is_callable(array($field['USER_TYPE']['CLASS_NAME'], 'GetList')))
				{
					$enumList = array();
					$enumQty = 0;
					$enumResult = call_user_func_array(array($field['USER_TYPE']['CLASS_NAME'], 'GetList'), array($field));
					while($enum = $enumResult->Fetch())
					{
						unset($enum['ID']);
						$enumList["n{$enumQty}"] = $enum;
						$enumQty++;
					}

					$enumEntity = new \CUserFieldEnum();
					$enumEntity->SetEnumValues($ID, $enumList);
				}
			}

			$synchronizedFieldNameMap[$srcField['FIELD_NAME']] = $fieldName;
		}

		//remove this branch if proper bugfix exists and this workaround is no longer needed
		if (!empty($fieldsToCreate) || !empty($fieldsToDelete))
		{
			$dstFactory = Container::getInstance()->getFactory((int)$dstEntityTypeID);
			if ($dstFactory)
			{
				$dstDataClass = $dstFactory->getDataClass();

				//rebuild entity so that new user fields are added to map and old fields are removed
				Main\ORM\Entity::destroy($dstDataClass::getEntity());

				if ($dstFactory instanceof Factory\Dynamic)
				{
					//if we do not recompile entity for dynamic type, some fields will be absent
					Model\Dynamic\TypeTable::compileEntity($dstFactory->getType());
				}
			}
		}

		$historyItem = self::getHistoryItem($srcEntityTypeID, $dstEntityTypeID);
		if($historyItem === null)
		{
			$historyItem = array();
		}

		$historyItem['sync'] = new DateTime();
		$historyItem['check'] = new DateTime();
		$historyItem['required'] = false;
		$historyItem['version'] = self::COMPATIBILITY_HISTORY_VERSION;
		self::setHistoryItem($srcEntityTypeID, $dstEntityTypeID, $historyItem);

		return array_merge(self::$existedFieldNameMap, $synchronizedFieldNameMap);
	}

	private static function filterFieldsByName(array $fields, array $fieldNames): array
	{
		$fieldNameMap = array_fill_keys($fieldNames, true);

		return array_values(
			array_filter(
				$fields,
				static function(array $field) use ($fieldNameMap): bool {
					return isset($fieldNameMap[$field['FIELD_NAME'] ?? null]);
				}
			)
		);
	}

	public static function markAsSynchronized($srcEntityTypeID, $dstEntityTypeID)
	{
		$historyItem = self::getHistoryItem($srcEntityTypeID, $dstEntityTypeID);
		if($historyItem === null)
		{
			$historyItem = array();
		}

		$historyItem['check'] = new DateTime();
		$historyItem['required'] = false;
		$historyItem['version'] = self::COMPATIBILITY_HISTORY_VERSION;
		self::setHistoryItem($srcEntityTypeID, $dstEntityTypeID, $historyItem);
	}
	/**
	* Compares source type fields with destination type fields.
	* Matches are searched by comparing field compatibility.
	* @static
	* @param int $srcEntityTypeID Source Entity Type ID
	* @param int $dstEntityTypeID Destination Entity Type ID
	* @param string $languageID Language
	* @return array
	*/
	public static function getIntersection(
		$srcEntityTypeID,
		$dstEntityTypeID,
		$languageID = '',
		bool $allowUnsupportedTypes = false,
		bool $allowRecyclingCompatibility = false,
		bool $matchByBasicAttributesOnly = false
	)
	{
		if(!is_string($languageID) || $languageID === '')
		{
			$languageID = LANGUAGE_ID;
		}

		$srcUfEntityID = \CCrmOwnerType::ResolveUserFieldEntityID($srcEntityTypeID);
		$dstUfEntityID = \CCrmOwnerType::ResolveUserFieldEntityID($dstEntityTypeID);

		/** @var \CUserTypeManager $USER_FIELD_MANAGER */
		global $USER_FIELD_MANAGER;
		/** @var \CMain $APPLICATION */
		global $APPLICATION;

		$srcFields = $USER_FIELD_MANAGER->GetUserFields($srcUfEntityID, 0, $languageID);
		$dstFields = $USER_FIELD_MANAGER->GetUserFields($dstUfEntityID, 0, $languageID);

		return self::getIntersectionFields(
			$srcFields,
			$dstFields,
			[],
			$allowUnsupportedTypes,
			$allowRecyclingCompatibility,
			$matchByBasicAttributesOnly
		);
	}

	public static function getIntersectionFields(
		array $srcFields,
		array $dstFields,
		array $excludedDstFieldNames = [],
		bool $allowUnsupportedTypes = false,
		bool $allowRecyclingCompatibility = false,
		bool $matchByBasicAttributesOnly = false,
		?callable $destinationFieldFilter = null,
	): array
	{
		$map = self::prepareCompatibilityFieldMap($dstFields, $allowUnsupportedTypes);
		self::preloadEnumerationValueHashes($srcFields, $map);
		$excludedDstFieldNameMap = array_fill_keys($excludedDstFieldNames, true);

		$results = [];

		foreach($srcFields as $field)
		{
			$label = self::getFieldComplianceCode($field);
			if($label === '')
			{
				continue;
			}

			$typeID = $field['USER_TYPE_ID'];
			if(!$allowUnsupportedTypes && !self::isUserFieldTypeSupported($typeID))
			{
				continue;
			}

			if(!self::hasCompatibleFieldCandidates($field, $map, $allowRecyclingCompatibility, $matchByBasicAttributesOnly))
			{
				continue;
			}

			$compatibilityKey = self::getCompatibilityKey($field, $matchByBasicAttributesOnly);
			if($compatibilityKey === '' || isset($results[$compatibilityKey]))
			{
				continue;
			}

			$dstFieldName = self::findCompatibleFieldName(
				$field,
				$map,
				$excludedDstFieldNameMap,
				true,
				$allowRecyclingCompatibility,
				$allowUnsupportedTypes,
				$matchByBasicAttributesOnly,
				$destinationFieldFilter,
			);
			if($dstFieldName !== null)
			{
				$results[$compatibilityKey] = [
					'LABEL' => $label,
					'SRC_FIELD_NAME' => $field['FIELD_NAME'],
					'DST_FIELD_NAME' => $dstFieldName,
				];
			}
		}

		return $results;
	}

	/**
	 * Checks whether two user fields can safely share conversion or synchronization mapping.
	 *
	 * @param array $srcField Source user field.
	 * @param array $dstField Destination user field.
	 * @return bool
	 */
	public static function areUserFieldsCompatible(
		array $srcField,
		array $dstField,
		bool $allowRecyclingCompatibility = false,
		bool $allowUnsupportedTypes = false,
		bool $matchByBasicAttributesOnly = false
	): bool
	{
		$srcTypeID = $srcField['USER_TYPE_ID'] ?? '';
		$dstTypeID = $dstField['USER_TYPE_ID'] ?? '';
		if($srcTypeID === '' || $srcTypeID !== $dstTypeID)
		{
			return false;
		}

		if(!$allowUnsupportedTypes && !self::isUserFieldTypeSupported($srcTypeID))
		{
			return false;
		}

		$srcMultiple = ($srcField['MULTIPLE'] ?? 'N') === 'Y' ? 'Y' : 'N';
		$dstMultiple = ($dstField['MULTIPLE'] ?? 'N') === 'Y' ? 'Y' : 'N';
		if($srcMultiple !== $dstMultiple)
		{
			return false;
		}

		if(self::getFieldComplianceCode($srcField) !== self::getFieldComplianceCode($dstField))
		{
			return false;
		}

		if($matchByBasicAttributesOnly && self::canUseValueValidatedRecyclingCompatibility($srcTypeID))
		{
			return true;
		}

		if($srcTypeID === 'enumeration')
		{
			return $allowRecyclingCompatibility
				? self::areEnumerationFieldsOverlapping($srcField, $dstField)
				: self::areEnumerationFieldsCompatible($srcField, $dstField)
			;
		}

		if($allowRecyclingCompatibility)
		{
			return self::areSignificantSettingsCompatibleForRecycle($srcField, $dstField);
		}

		return self::getSignificantSettingsSignature($srcField) === self::getSignificantSettingsSignature($dstField);
	}

	private static function prepareCompatibilityFieldMap(array $fields, bool $allowUnsupportedTypes = false): array
	{
		$result = [];
		foreach($fields as $field)
		{
			$label = self::getFieldComplianceCode($field);
			$typeID = $field['USER_TYPE_ID'] ?? '';
			if(
				$label === ''
				|| $typeID === ''
				|| (!$allowUnsupportedTypes && !self::isUserFieldTypeSupported($typeID))
			)
			{
				continue;
			}

			$isMultiple = ($field['MULTIPLE'] ?? 'N') === 'Y' ? 'Y' : 'N';
			$settingsSignature = $typeID === 'enumeration' ? '' : self::getSignificantSettingsSignature($field);
			$result[$typeID][$isMultiple][$label][$settingsSignature][] = [
				'FIELD' => $field,
				'NAME' => $field['FIELD_NAME'],
				'IS_BUSY' => false,
			];
		}

		return $result;
	}

	private static function hasCompatibleFieldCandidates(
		array $srcField,
		array $map,
		bool $allowRecyclingCompatibility = false,
		bool $matchByBasicAttributesOnly = false
	): bool
	{
		$label = self::getFieldComplianceCode($srcField);
		$typeID = $srcField['USER_TYPE_ID'] ?? '';
		$isMultiple = ($srcField['MULTIPLE'] ?? 'N') === 'Y' ? 'Y' : 'N';
		if($label === '' || $typeID === '' || !isset($map[$typeID][$isMultiple][$label]))
		{
			return false;
		}

		if(
			$typeID === 'enumeration'
			|| $allowRecyclingCompatibility
			|| (
				$matchByBasicAttributesOnly
				&& self::canUseValueValidatedRecyclingCompatibility($typeID)
			)
		)
		{
			return true;
		}

		$settingsSignature = self::getSignificantSettingsSignature($srcField);

		return isset($map[$typeID][$isMultiple][$label][$settingsSignature]);
	}

	private static function findCompatibleFieldName(
		array $srcField,
		array &$map,
		array $excludedFieldNames = [],
		bool $markAsBusy = true,
		bool $allowRecyclingCompatibility = false,
		bool $allowUnsupportedTypes = false,
		bool $matchByBasicAttributesOnly = false,
		?callable $destinationFieldFilter = null,
	): ?string
	{
		$label = self::getFieldComplianceCode($srcField);
		$typeID = $srcField['USER_TYPE_ID'] ?? '';
		$isMultiple = ($srcField['MULTIPLE'] ?? 'N') === 'Y' ? 'Y' : 'N';
		if(
			$label === ''
			|| $typeID === ''
			|| !isset($map[$typeID][$isMultiple][$label])
		)
		{
			return null;
		}

		$settingsSignatures = (
			$typeID === 'enumeration'
			|| $allowRecyclingCompatibility
			|| (
				$matchByBasicAttributesOnly
				&& self::canUseValueValidatedRecyclingCompatibility($typeID)
			)
		)
			? array_keys($map[$typeID][$isMultiple][$label])
			: [self::getSignificantSettingsSignature($srcField)]
		;

		foreach($settingsSignatures as $settingsSignature)
		{
			if(!isset($map[$typeID][$isMultiple][$label][$settingsSignature]))
			{
				continue;
			}

			foreach($map[$typeID][$isMultiple][$label][$settingsSignature] as &$candidate)
			{
				if(
					$candidate['IS_BUSY']
					|| isset($excludedFieldNames[$candidate['NAME']])
					|| !self::areUserFieldsCompatible(
						$srcField,
						$candidate['FIELD'],
						$allowRecyclingCompatibility,
						$allowUnsupportedTypes,
						$matchByBasicAttributesOnly
					)
					|| (
						$destinationFieldFilter !== null
						&& !$destinationFieldFilter($srcField, $candidate['FIELD'])
					)
				)
				{
					continue;
				}

				if($markAsBusy)
				{
					$candidate['IS_BUSY'] = true;
				}

				return $candidate['NAME'];
			}
			unset($candidate);
		}

		return null;
	}

	private static function getCompatibilityKey(array $field, bool $matchByBasicAttributesOnly = false): string
	{
		$label = self::getFieldComplianceCode($field);
		$typeID = $field['USER_TYPE_ID'] ?? '';
		if($label === '' || $typeID === '')
		{
			return '';
		}

		$isMultiple = ($field['MULTIPLE'] ?? 'N') === 'Y' ? 'Y' : 'N';

		return $typeID . ':' . $isMultiple . ':' . $label . ':'
			. (
				$matchByBasicAttributesOnly && self::canUseValueValidatedRecyclingCompatibility($typeID)
					? ''
					: self::getSignificantSettingsSignature($field)
			)
		;
	}

	private static function canUseValueValidatedRecyclingCompatibility(string $typeID): bool
	{
		return isset(self::VALUE_VALIDATED_RECYCLING_TYPES[$typeID]);
	}

	private static function getSignificantSettingsSignature(array $field): string
	{
		$typeID = $field['USER_TYPE_ID'] ?? '';
		if($typeID === 'enumeration')
		{
			return implode('|', self::getEnumerationValueHashes($field));
		}

		$settings = isset($field['SETTINGS']) && is_array($field['SETTINGS']) ? $field['SETTINGS'] : [];
		$significantSettings = [];
		foreach(self::getSignificantSettingNames($typeID, $settings) as $settingName)
		{
			$value = array_key_exists($settingName, $settings) ? $settings[$settingName] : null;
			$significantSettings[$settingName] = self::normalizeSignificantSettingValue(
				$typeID,
				$settingName,
				$value
			);
		}

		if(empty($significantSettings))
		{
			return '';
		}

		ksort($significantSettings);

		return md5(serialize($significantSettings));
	}

	private static function getSignificantSettingNames(string $typeID, array $settings = []): array
	{
		switch($typeID)
		{
			case 'integer':
			case 'double':
				return ['MIN_VALUE', 'MAX_VALUE', 'PRECISION'];

			case 'datetime':
				return ['USE_TIMEZONE'];

			case 'string':
			case 'string_formatted':
				return ['MIN_LENGTH', 'MAX_LENGTH', 'REGEXP'];

			case 'url':
				return ['MIN_LENGTH', 'MAX_LENGTH'];

			case 'file':
				return ['MAX_ALLOWED_SIZE', 'EXTENSIONS'];

			case 'crm':
				$names = ['LEAD', 'CONTACT', 'COMPANY', 'DEAL', 'QUOTE', 'SMART_INVOICE', 'ORDER'];
				foreach($settings as $name => $value)
				{
					if(
						is_string($name)
						&& self::isDynamicCrmSettingName($name)
						&& self::normalizeSignificantSettingValue($typeID, $name, $value) === 'Y'
					)
					{
						$names[] = $name;
					}
				}

				return $names;

			case 'crm_status':
				return ['ENTITY_TYPE'];

			case 'hlblock':
				return ['HLBLOCK_ID'];

			case 'iblock_element':
			case 'iblock_section':
				return ['IBLOCK_ID'];

			default:
				return [];
		}
	}

	private static function isDynamicCrmSettingName(string $name): bool
	{
		return preg_match('/^DYNAMIC_\\d+$/', $name) === 1;
	}

	private static function normalizeSignificantSettingValue(string $typeID, string $settingName, mixed $value): mixed
	{
		if(
			$typeID === 'crm'
			&& (
				in_array($settingName, ['LEAD', 'CONTACT', 'COMPANY', 'DEAL', 'QUOTE', 'SMART_INVOICE', 'ORDER'], true)
				|| self::isDynamicCrmSettingName($settingName)
			)
		)
		{
			return $value === 'Y' ? 'Y' : 'N';
		}

		if($typeID === 'datetime' && $settingName === 'USE_TIMEZONE')
		{
			return $value === 'N' ? 'N' : 'Y';
		}

		if($typeID === 'double' && in_array($settingName, ['MIN_VALUE', 'MAX_VALUE'], true))
		{
			return (float)$value;
		}

		if(
			in_array($settingName, ['MIN_VALUE', 'MAX_VALUE', 'PRECISION', 'MIN_LENGTH', 'MAX_LENGTH'], true)
			|| ($typeID === 'file' && $settingName === 'MAX_ALLOWED_SIZE')
		)
		{
			return (int)$value;
		}

		if(
			in_array($typeID, ['string', 'string_formatted'], true)
			&& $settingName === 'REGEXP'
		)
		{
			return is_string($value) ? $value : '';
		}

		if($typeID === 'file' && $settingName === 'EXTENSIONS')
		{
			return self::normalizeFileExtensions($value);
		}

		return $value;
	}

	private static function normalizeFileExtensions(mixed $value): array
	{
		if($value === null || $value === '')
		{
			return [];
		}

		$extensions = is_array($value) ? $value : explode(',', (string)$value);
		$result = [];
		foreach($extensions as $key => $extension)
		{
			if($extension === true)
			{
				$extension = $key;
			}

			$extension = mb_strtolower(trim((string)$extension));
			if($extension !== '')
			{
				$result[$extension] = true;
			}
		}
		ksort($result);

		return array_keys($result);
	}

	private static function areSignificantSettingsCompatibleForRecycle(array $srcField, array $dstField): bool
	{
		$typeID = $srcField['USER_TYPE_ID'] ?? '';
		$srcSettings = isset($srcField['SETTINGS']) && is_array($srcField['SETTINGS']) ? $srcField['SETTINGS'] : [];
		$dstSettings = isset($dstField['SETTINGS']) && is_array($dstField['SETTINGS']) ? $dstField['SETTINGS'] : [];

		switch($typeID)
		{
			case 'integer':
			case 'double':
				return self::isMinimumRestrictionCompatible($srcSettings, $dstSettings, $typeID, 'MIN_VALUE')
					&& self::isMaximumRestrictionCompatible($srcSettings, $dstSettings, $typeID, 'MAX_VALUE')
					&& self::isPrecisionRestrictionCompatible($srcSettings, $dstSettings, $typeID)
				;

			case 'string':
			case 'string_formatted':
				return self::isMinimumRestrictionCompatible($srcSettings, $dstSettings, $typeID, 'MIN_LENGTH')
					&& self::isMaximumRestrictionCompatible($srcSettings, $dstSettings, $typeID, 'MAX_LENGTH')
					&& self::isRegexpRestrictionCompatible($srcSettings, $dstSettings, $typeID)
				;

			case 'url':
				return self::isMinimumRestrictionCompatible($srcSettings, $dstSettings, $typeID, 'MIN_LENGTH')
					&& self::isMaximumRestrictionCompatible($srcSettings, $dstSettings, $typeID, 'MAX_LENGTH')
				;

			case 'file':
				return self::isMaximumRestrictionCompatible($srcSettings, $dstSettings, $typeID, 'MAX_ALLOWED_SIZE')
					&& self::isFileExtensionsRestrictionCompatible($srcSettings, $dstSettings)
				;

			default:
				return self::getSignificantSettingsSignature($srcField) === self::getSignificantSettingsSignature($dstField);
		}
	}

	private static function isMinimumRestrictionCompatible(
		array $srcSettings,
		array $dstSettings,
		string $typeID,
		string $settingName
	): bool
	{
		$srcValue = self::normalizeSignificantSettingValue($typeID, $settingName, $srcSettings[$settingName] ?? null);
		$dstValue = self::normalizeSignificantSettingValue($typeID, $settingName, $dstSettings[$settingName] ?? null);

		return $dstValue <= $srcValue;
	}

	private static function isMaximumRestrictionCompatible(
		array $srcSettings,
		array $dstSettings,
		string $typeID,
		string $settingName
	): bool
	{
		$srcValue = self::normalizeSignificantSettingValue($typeID, $settingName, $srcSettings[$settingName] ?? null);
		$dstValue = self::normalizeSignificantSettingValue($typeID, $settingName, $dstSettings[$settingName] ?? null);
		if($srcValue <= 0 || $dstValue <= 0)
		{
			return $dstValue <= 0;
		}

		return $srcValue <= $dstValue;
	}

	private static function isRegexpRestrictionCompatible(array $srcSettings, array $dstSettings, string $typeID): bool
	{
		$srcRegexp = self::normalizeSignificantSettingValue($typeID, 'REGEXP', $srcSettings['REGEXP'] ?? null);
		$dstRegexp = self::normalizeSignificantSettingValue($typeID, 'REGEXP', $dstSettings['REGEXP'] ?? null);

		return $dstRegexp === '' || $dstRegexp === $srcRegexp;
	}

	private static function isPrecisionRestrictionCompatible(array $srcSettings, array $dstSettings, string $typeID): bool
	{
		$srcPrecision = self::normalizeSignificantSettingValue($typeID, 'PRECISION', $srcSettings['PRECISION'] ?? null);
		$dstPrecision = self::normalizeSignificantSettingValue($typeID, 'PRECISION', $dstSettings['PRECISION'] ?? null);

		return $srcPrecision <= $dstPrecision;
	}

	private static function isFileExtensionsRestrictionCompatible(array $srcSettings, array $dstSettings): bool
	{
		$srcExtensions = self::normalizeFileExtensions($srcSettings['EXTENSIONS'] ?? null);
		$dstExtensions = self::normalizeFileExtensions($dstSettings['EXTENSIONS'] ?? null);
		if(empty($dstExtensions))
		{
			return true;
		}

		if(empty($srcExtensions))
		{
			return false;
		}

		$dstExtensionMap = array_fill_keys($dstExtensions, true);
		foreach($srcExtensions as $extension)
		{
			if(!isset($dstExtensionMap[$extension]))
			{
				return false;
			}
		}

		return true;
	}

	private static function areEnumerationFieldsCompatible(array $srcField, array $dstField): bool
	{
		$srcHashes = self::getEnumerationValueHashes($srcField);
		if(empty($srcHashes))
		{
			return true;
		}

		$dstHashes = array_flip(self::getEnumerationValueHashes($dstField));
		foreach($srcHashes as $srcHash)
		{
			if(!isset($dstHashes[$srcHash]))
			{
				return false;
			}
		}

		return true;
	}

	private static function areEnumerationFieldsOverlapping(array $srcField, array $dstField): bool
	{
		$srcHashes = self::getEnumerationValueHashes($srcField);
		if(empty($srcHashes))
		{
			return true;
		}

		$dstHashes = array_flip(self::getEnumerationValueHashes($dstField));
		foreach($srcHashes as $srcHash)
		{
			if(isset($dstHashes[$srcHash]))
			{
				return true;
			}
		}

		return false;
	}

	public static function preloadEnumerationValues(array $fields): void
	{
		self::loadEnumerationValueHashes($fields);
	}

	public static function clearEnumerationValueCache(int $fieldId): void
	{
		unset(self::$enumerationValueHashes[$fieldId], self::$enumerationValues[$fieldId]);
	}

	public static function filterFieldsWithValue(array $fields): array
	{
		$result = [];
		foreach($fields as $fieldName => $field)
		{
			if(!is_array($field) || !self::isUserFieldValueFilled($field['VALUE'] ?? null))
			{
				continue;
			}

			$result[$fieldName] = $field;
		}

		return $result;
	}

	public static function tryMapEnumerationValueToDestination(array $srcField, array $dstField, mixed $value, mixed &$mappedValue): bool
	{
		$mappedValue = $value;
		if(
			($srcField['USER_TYPE_ID'] ?? '') !== 'enumeration'
			|| ($dstField['USER_TYPE_ID'] ?? '') !== 'enumeration'
		)
		{
			return true;
		}

		$selectedValues = self::normalizeEnumerationSelectedValues($value);
		if(empty($selectedValues))
		{
			$mappedValue = ($srcField['MULTIPLE'] ?? 'N') === 'Y' ? [] : null;
			return true;
		}

		$srcValueMap = self::getEnumerationValueMapById($srcField);
		$dstValueMap = self::getEnumerationIdMapByValueHash($dstField);
		$mappedValues = [];
		$isMultiple = ($srcField['MULTIPLE'] ?? 'N') === 'Y';
		foreach($selectedValues as $selectedValue)
		{
			$srcValue = $srcValueMap[(string)$selectedValue] ?? null;
			if($srcValue === null)
			{
				if($isMultiple)
				{
					continue;
				}

				return false;
			}

			$hash = md5((string)$srcValue);
			if(!isset($dstValueMap[$hash]))
			{
				if($isMultiple)
				{
					continue;
				}

				return false;
			}

			$mappedValues[] = $dstValueMap[$hash];
		}

		if(empty($mappedValues))
		{
			return false;
		}

		$mappedValue = $isMultiple
			? $mappedValues
			: $mappedValues[0]
		;

		return true;
	}

	private static function isUserFieldValueFilled(mixed $value): bool
	{
		if(is_array($value))
		{
			foreach($value as $singleValue)
			{
				if(self::isUserFieldValueFilled($singleValue))
				{
					return true;
				}
			}

			return false;
		}

		return $value !== null && $value !== '' && $value !== false;
	}

	private static function preloadEnumerationValueHashes(
		array $srcFields,
		array $map,
		bool $includeMissingSourceFields = false
	): void
	{
		$fields = [];
		foreach($srcFields as $srcField)
		{
			if(($srcField['USER_TYPE_ID'] ?? '') !== 'enumeration')
			{
				continue;
			}

			$label = self::getFieldComplianceCode($srcField);
			$isMultiple = ($srcField['MULTIPLE'] ?? 'N') === 'Y' ? 'Y' : 'N';
			if($label === '')
			{
				continue;
			}

			if(!isset($map['enumeration'][$isMultiple][$label]))
			{
				if($includeMissingSourceFields)
				{
					$fields[] = $srcField;
				}

				continue;
			}

			$fields[] = $srcField;
			foreach($map['enumeration'][$isMultiple][$label] as $candidates)
			{
				foreach($candidates as $candidate)
				{
					$fields[] = $candidate['FIELD'];
				}
			}
		}

		self::loadEnumerationValueHashes($fields);
	}

	private static function loadEnumerationValueHashes(array $fields): void
	{
		$fieldIds = [];
		foreach($fields as $field)
		{
			$fieldId = (int)($field['ID'] ?? 0);
			if($fieldId <= 0 || array_key_exists($fieldId, self::$enumerationValueHashes))
			{
				continue;
			}

			if(isset($field['ENUM']) && is_array($field['ENUM']))
			{
				self::setCachedEnumerationValues($fieldId, $field['ENUM']);
			}
			elseif(isset($field['VALUES']) && is_array($field['VALUES']))
			{
				self::setCachedEnumerationValues($fieldId, $field['VALUES']);
			}
			elseif(($field['USER_TYPE_ID'] ?? '') === 'enumeration')
			{
				$fieldIds[$fieldId] = $fieldId;
			}
		}

		if(empty($fieldIds))
		{
			return;
		}

		foreach($fieldIds as $fieldId)
		{
			self::$enumerationValueHashes[$fieldId] = [];
			self::$enumerationValues[$fieldId] = [];
		}

		foreach(array_chunk(array_values($fieldIds), self::ENUMERATION_VALUE_HASH_CHUNK_SIZE) as $fieldIdChunk)
		{
			$dbResult = \CUserFieldEnum::GetList(
				['USER_FIELD_ID' => 'ASC'],
				['USER_FIELD_ID' => $fieldIdChunk],
				false
			);
			while($enum = $dbResult->Fetch())
			{
				$fieldId = (int)($enum['USER_FIELD_ID'] ?? 0);
				if($fieldId <= 0 || !isset(self::$enumerationValueHashes[$fieldId]))
				{
					continue;
				}

				$value = $enum['VALUE'] ?? null;
				if($value !== null)
				{
					self::$enumerationValueHashes[$fieldId][] = md5((string)$value);
					self::$enumerationValues[$fieldId][] = $enum;
				}
			}
		}

		foreach($fieldIds as $fieldId)
		{
			self::$enumerationValueHashes[$fieldId] = array_values(
				array_unique(self::$enumerationValueHashes[$fieldId])
			);
			sort(self::$enumerationValueHashes[$fieldId]);
			self::$enumerationValues[$fieldId] = self::normalizeEnumerationValues(self::$enumerationValues[$fieldId]);
		}
	}

	private static function getEnumerationValueHashes(array $field): array
	{
		$fieldId = (int)($field['ID'] ?? 0);
		if($fieldId > 0 && array_key_exists($fieldId, self::$enumerationValueHashes))
		{
			return self::$enumerationValueHashes[$fieldId];
		}

		$values = self::getEnumerationValues($field);
		$hashes = self::prepareEnumerationValueHashes($values);
		if($fieldId > 0)
		{
			self::$enumerationValueHashes[$fieldId] = $hashes;
			self::$enumerationValues[$fieldId] = self::normalizeEnumerationValues($values);
		}

		return $hashes;
	}

	private static function getEnumerationValues(array $field): array
	{
		$fieldId = (int)($field['ID'] ?? 0);
		if($fieldId > 0 && array_key_exists($fieldId, self::$enumerationValues))
		{
			return self::$enumerationValues[$fieldId];
		}

		if(isset($field['ENUM']) && is_array($field['ENUM']))
		{
			$values = $field['ENUM'];
		}
		elseif(isset($field['VALUES']) && is_array($field['VALUES']))
		{
			$values = $field['VALUES'];
		}
		elseif(($field['USER_TYPE_ID'] ?? '') === 'enumeration' && $fieldId > 0)
		{
			$values = [];
			$dbResult = \CUserFieldEnum::GetList(
				['USER_FIELD_ID' => 'ASC'],
				['USER_FIELD_ID' => $fieldId],
				false
			);
			while($enum = $dbResult->Fetch())
			{
				$values[] = $enum;
			}
		}
		else
		{
			$values = [];
		}

		$values = self::normalizeEnumerationValues($values);
		if($fieldId > 0)
		{
			self::$enumerationValues[$fieldId] = $values;
		}

		return $values;
	}

	private static function setCachedEnumerationValues(int $fieldId, array $values): void
	{
		self::$enumerationValues[$fieldId] = self::normalizeEnumerationValues($values);
		self::$enumerationValueHashes[$fieldId] = self::prepareEnumerationValueHashes(
			self::$enumerationValues[$fieldId]
		);
	}

	private static function normalizeEnumerationValues(array $values): array
	{
		$result = [];
		foreach($values as $value)
		{
			if(!is_array($value) || !isset($value['ID']) || !array_key_exists('VALUE', $value))
			{
				continue;
			}

			$result[] = $value;
		}

		return $result;
	}

	private static function getEnumerationValueMapById(array $field): array
	{
		$map = [];
		foreach(self::getEnumerationValues($field) as $value)
		{
			if(!is_array($value) || !isset($value['ID']) || !array_key_exists('VALUE', $value))
			{
				continue;
			}

			$map[(string)$value['ID']] = $value['VALUE'];
		}

		return $map;
	}

	private static function getEnumerationIdMapByValueHash(array $field): array
	{
		$map = [];
		foreach(self::getEnumerationValues($field) as $value)
		{
			if(!is_array($value) || !isset($value['ID']) || !array_key_exists('VALUE', $value))
			{
				continue;
			}

			$hash = md5((string)$value['VALUE']);
			if(!isset($map[$hash]))
			{
				$map[$hash] = $value['ID'];
			}
		}

		return $map;
	}

	private static function normalizeEnumerationSelectedValues(mixed $value): array
	{
		$values = is_array($value) ? $value : [$value];
		$result = [];
		foreach($values as $selectedValue)
		{
			if(is_array($selectedValue))
			{
				$selectedValue = $selectedValue['VALUE'] ?? $selectedValue['ID'] ?? null;
			}

			if($selectedValue === null || $selectedValue === '')
			{
				continue;
			}

			$result[] = $selectedValue;
		}

		return $result;
	}

	private static function prepareEnumerationValueHashes(array $values): array
	{
		$hashes = [];
		foreach($values as $value)
		{
			$value = is_array($value) ? ($value['VALUE'] ?? null) : $value;
			if($value !== null)
			{
				$hashes[] = md5((string)$value);
			}
		}

		$hashes = array_values(array_unique($hashes));
		sort($hashes);

		return $hashes;
	}

	public static function getDifference($srcEntityTypeID, $dstEntityTypeID, $languageID = '')
	{
		if(!is_int($srcEntityTypeID))
		{
			$srcEntityTypeID = (int)$srcEntityTypeID;
		}


		if(!is_int($dstEntityTypeID))
		{
			$dstEntityTypeID = (int)$dstEntityTypeID;
		}

		if($srcEntityTypeID === $dstEntityTypeID
			|| !\CCrmOwnerType::IsDefined($srcEntityTypeID)
			|| !\CCrmOwnerType::IsDefined($dstEntityTypeID)
		)
		{
			return array();
		}

		if(!is_string($languageID) || $languageID === '')
		{
			$languageID = LANGUAGE_ID;
		}

		$srcUfEntityID = \CCrmOwnerType::ResolveUserFieldEntityID($srcEntityTypeID);
		$dstUfEntityID = \CCrmOwnerType::ResolveUserFieldEntityID($dstEntityTypeID);

		if($srcUfEntityID === '' || $dstUfEntityID === '')
		{
			return array();
		}

		/** @var \CAllUserTypeManager $USER_FIELD_MANAGER */
		global $USER_FIELD_MANAGER;
		$srcFields = $USER_FIELD_MANAGER->GetUserFields($srcUfEntityID, 0, $languageID);
		$dstFields = $USER_FIELD_MANAGER->GetUserFields($dstUfEntityID, 0, $languageID);

		return self::getDifferenceFromOther($srcFields, $dstFields);
	}
	protected static function prepareFieldMap(array $fields)
	{
		$result = array();
		foreach($fields as $field)
		{
			$label = self::getFieldComplianceCode($field);
			if($label === '')
			{
				continue;
			}

			$typeID = $field['USER_TYPE_ID'];
			if(!isset($result[$typeID]))
			{
				$result[$typeID] = array();
			}

			$isMultiple = $field['MULTIPLE'] === 'Y' ? 'Y' : 'N';
			if(!isset($result[$typeID][$isMultiple]))
			{
				$result[$typeID][$isMultiple] = array();
			}

			if(!isset($result[$typeID][$isMultiple][$label]))
			{
				$result[$typeID][$isMultiple][$label] = $field['FIELD_NAME'];
			}
		}
		return $result;
	}
	public static function getFieldComplianceCode(array $field)
	{
		$label = isset($field['EDIT_FORM_LABEL']) ? $field['EDIT_FORM_LABEL'] : '';
		if($label === '' && isset($field['LIST_COLUMN_LABEL']))
		{
			$label = $field['LIST_COLUMN_LABEL'];
		}

		return $label !== ''? mb_strtolower(str_replace(' ', '', $label)) : '';
	}
	public static function getFieldLabel(array $field)
	{
		$label = isset($field['EDIT_FORM_LABEL']) ? $field['EDIT_FORM_LABEL'] : '';
		if($label === '' && isset($field['LIST_COLUMN_LABEL']))
		{
			$label = $field['LIST_COLUMN_LABEL'];
		}

		return $label;
	}
	public static function getHistoryItem($srcEntityTypeID, $dstEntityTypeID)
	{
		$key = "{$srcEntityTypeID}_{$dstEntityTypeID}";
		$history = self::getHistory();
		return isset($history[$key]) ? $history[$key] : null;
	}
	public static function setHistoryItem($srcEntityTypeID, $dstEntityTypeID, array $historyItem)
	{
		$history = self::getHistory();
		$key = "{$srcEntityTypeID}_{$dstEntityTypeID}";

		$historyItem['src'] = $srcEntityTypeID;
		$historyItem['dst'] = $dstEntityTypeID;
		$history[$key] = $historyItem;
		self::setHistory($history);
	}
	public static function removeHistoryItem($srcEntityTypeID, $dstEntityTypeID)
	{
		$history = self::getHistory();
		unset($history["{$srcEntityTypeID}_{$dstEntityTypeID}"]);
		self::setHistory($history);
	}
	/**
	* Get synchronization history.
	* @return array
	*/
	protected static function getHistory()
	{
		if(self::$history !== null)
		{
			return self::$history;
		}

		self::$history = array();
		$s = Main\Config\Option::get('crm', 'crm_uf_sync_history', '', '');
		$ary = $s !== '' ? unserialize($s, ['allowed_classes' => false]) : null;
		if(is_array($ary))
		{
			foreach($ary as $item)
			{
				if(!is_array($item))
				{
					continue;
				}

				$srcEntityTypeID = \CCrmOwnerType::ResolveID(isset($item['src']) ? $item['src'] : '');
				$dstEntityTypeID = \CCrmOwnerType::ResolveID(isset($item['dst']) ? $item['dst'] : '');

				if($srcEntityTypeID === \CCrmOwnerType::Undefined || $dstEntityTypeID === \CCrmOwnerType::Undefined)
				{
					continue;
				}

				$sync = isset($item['sync']) ? $item['sync'] : '';
				$check = isset($item['check']) ? $item['check'] : '';
				try
				{
					self::$history["{$srcEntityTypeID}_{$dstEntityTypeID}"] = array(
						'src' => $srcEntityTypeID,
						'dst' => $dstEntityTypeID,
						'sync' => $sync !== '' ? new DateTime($sync, \DateTime::ISO8601) : null,
						'check' => $check !== '' ? new DateTime($check, \DateTime::ISO8601) : null,
						'required' => isset($item['required']) ? $item['required'] : null,
						'version' => isset($item['version']) ? (int)$item['version'] : 0,
					);
				}
				catch(Main\ObjectException $e)
				{
				}
			}
		}

		return self::$history;
	}
	/**
	* Set synchronization history.
	* @param array $history History
	* @return void
	*/
	protected static function setHistory(array $history)
	{
		self::$history = $history;
		$ary = array();
		foreach(self::$history as $item)
		{
			/** @var DateTime $sync */
			$sync = isset($item['sync']) ? $item['sync'] : null;
			/** @var DateTime $check */
			$check = isset($item['check']) ? $item['check'] : null;

			$ary[] = array(
				'src' => \CCrmOwnerType::ResolveName($item['src']),
				'dst' => \CCrmOwnerType::ResolveName($item['dst']),
				'sync' => $sync !== null ? $sync->format(\DateTime::ISO8601) : '',
				'check' => $check !== null ? $check->format(\DateTime::ISO8601) : '',
				'required' => isset($item['required']) ? $item['required'] : null,
				'version' => isset($item['version']) ? (int)$item['version'] : 0,
			);
		}

		Main\Config\Option::set('crm', 'crm_uf_sync_history', serialize($ary), '');
	}

	private static function getDifferenceFromOther(array $srcFields, array $dstFields): array
	{
		$dstMap = self::prepareCompatibilityFieldMap($dstFields);
		self::preloadEnumerationValueHashes($srcFields, $dstMap);
		$result = [];
		foreach($srcFields as $field)
		{
			$label = self::getFieldComplianceCode($field);
			if($label === '')
			{
				continue;
			}

			$typeID = $field['USER_TYPE_ID'];
			if(!self::isUserFieldTypeSupported($typeID))
			{
				continue;
			}

			$compatibilityKey = self::getCompatibilityKey($field);
			if(
				$compatibilityKey === ''
				|| isset($result[$compatibilityKey])
				|| self::findCompatibleFieldName($field, $dstMap, [], false) !== null
			)
			{
				continue;
			}

			$result[$compatibilityKey] = [
				'LABEL' => $label,
				'SRC_FIELD_NAME' => $field['FIELD_NAME'],
			];
		}

		return $result;
	}

	private static function isHistoryItemCompatible(array $historyItem): bool
	{
		return (int)($historyItem['version'] ?? 0) >= self::COMPATIBILITY_HISTORY_VERSION;
	}
}
