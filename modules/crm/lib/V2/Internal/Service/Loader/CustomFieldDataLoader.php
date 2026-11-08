<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Loader;

use Bitrix\Crm\StatusTable;
use Bitrix\Crm\Service\Display;
use Bitrix\Crm\Service\Display\Field;
use Bitrix\Crm\Service\Display\Options;
use Bitrix\Crm\Service\Broker\Enumeration;
use Bitrix\Crm\UserField\Visibility\VisibilityManager;
use Bitrix\Crm\V2\Public\Entity\Item\CustomField\Address;
use Bitrix\Crm\V2\Public\Entity\Item\CustomField\CrmStatus;
use Bitrix\Crm\V2\Public\Entity\Item\CustomField\CustomFieldData;
use Bitrix\Crm\V2\Public\Entity\Item\CustomField\IblockElement;
use Bitrix\Crm\V2\Public\Entity\Item\CustomField\IblockSection;
use Bitrix\Crm\V2\Public\Entity\Item\CustomField\Money;
use Bitrix\Crm\V2\Public\Entity\Item\Item;
use Bitrix\Crm\V2\Internal\Service\Loader\Value\FileMetadata;
use Bitrix\Crm\V2\Public\Entity\File;
use Bitrix\Crm\V2\Public\Entity\User\Employee;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Crm\V2\Public\EntityTypeSettings;
use Bitrix\Crm\V2\Public\ItemId;
use Bitrix\Crm\V2\Public\Provider\Item\AccessMode;
use Bitrix\Crm\V2\Public\Provider\Item\ItemProvider;
use Bitrix\Crm\V2\Public\Provider\Item\Param\ItemSelect;
use Bitrix\Main\Application;
use Bitrix\Main\FileTable;
use Bitrix\Main\Loader;
use Bitrix\Currency\Helpers\Editor;
use Bitrix\Currency\UserField\Types\MoneyType;
use Bitrix\Location\Entity\Address as LocationAddress;
use Bitrix\Location\Entity\Address\FieldType as LocationFieldType;

class CustomFieldDataLoader
{
	private const BATCH_SIZE = 500;
	private ?Enumeration $enumerationBroker = null;

	public function __construct(
		protected readonly ?int $accessUserId = null,
	)
	{
	}

	/**
	 * @param Item[] $items
	 */
	public function load(array $items, ItemSelect $select): void
	{
		$customFieldDataSelect = $select->getCustomFieldDataSelect();
		if ($customFieldDataSelect === [])
		{
			return;
		}

		/** @var Item[] $persisted */
		$persisted = array_values(array_filter($items, static fn(Item $item): bool => $item->getId() !== null));
		if (empty($persisted))
		{
			return;
		}

		$formattedValueSelect = array_filter(
			$customFieldDataSelect,
			static fn (array $select): bool => empty($select) || in_array('formattedValue', $select, true),
		);
		$extraValueSelect = array_filter(
			$customFieldDataSelect,
			static fn (array $select): bool => empty($select) || in_array('extra', $select, true),
		);

		$entityTypeId = $persisted[0]->getEntityType()->getId();
		$customFields = $this->getCustomFields($entityTypeId);
		$itemFieldValues = [];
		$itemFieldValuesToFormat = [];
		$itemFieldValuesToExtra = [];
		foreach ($persisted as $item)
		{
			$itemId = $item->getId();
			$itemFieldValues[$itemId] = array_intersect_key($item->getCustomFields(), $customFieldDataSelect);
			foreach ($itemFieldValues[$itemId] as $fieldName => $rawValue)
			{
				if (($customFields[$fieldName]['USER_TYPE_ID'] ?? null) !== 'file')
				{
					continue;
				}

				$normalizedValue = $this->normalizeFileValue($rawValue);
				$itemFieldValues[$itemId][$fieldName] = $normalizedValue;
			}
			if (!empty($formattedValueSelect))
			{
				$itemFieldValuesToFormat[$itemId] = array_intersect_key(
					$itemFieldValues[$itemId],
					$formattedValueSelect,
				);
			}
			if (!empty($extraValueSelect))
			{
				$itemFieldValuesToExtra[$itemId] = array_intersect_key(
					$itemFieldValues[$itemId],
					$extraValueSelect,
				);
			}
		}

		$formattableTypesFromExtra = $this->getFormattableTypesFromExtra();
		foreach (array_keys($formattedValueSelect) as $fieldNameToFormat)
		{
			if (!isset($customFields[$fieldNameToFormat]))
			{
				continue;
			}

			$type = $customFields[$fieldNameToFormat]['USER_TYPE_ID'] ?? '';
			if (isset($formattableTypesFromExtra[$type]) && !isset($extraValueSelect[$fieldNameToFormat]))
			{
				foreach ($itemFieldValues as $itemId => $fieldValues)
				{
					if (isset($fieldValues[$fieldNameToFormat]))
					{
						$itemFieldValuesToExtra[$itemId][$fieldNameToFormat] = $fieldValues[$fieldNameToFormat];
					}
				}
			}
		}

		$itemExtraValuesMap = $this->getExtraValuesMap($itemFieldValuesToExtra, $entityTypeId, $customFieldDataSelect);
		$itemFormattedValuesMap = $this->getFormattedValuesMap($itemFieldValuesToFormat, $entityTypeId, $itemExtraValuesMap);

		foreach ($persisted as $item)
		{
			$itemId = (int)$item->getId();
			foreach ($itemFieldValues[$itemId] ?? [] as $fieldName => $rawValue)
			{
				if (is_array($rawValue) && array_is_list($rawValue))
				{
					$customFieldData = [];
					foreach ($rawValue as $index => $rawValueItem)
					{
						$customFieldData[] = new CustomFieldData(
							value: $rawValueItem,
							formattedValue: $itemFormattedValuesMap[$itemId][$fieldName][$index] ?? null,
							extra: isset($extraValueSelect[$fieldName])
								? ($itemExtraValuesMap[$itemId][$fieldName][$index] ?? null)
								: null,
						);
					}
				}
				else
				{
					$customFieldData = new CustomFieldData(
						value: $rawValue,
						formattedValue: $itemFormattedValuesMap[$itemId][$fieldName] ?? null,
						extra: isset($extraValueSelect[$fieldName])
							? ($itemExtraValuesMap[$itemId][$fieldName] ?? null)
							: null,
					);
				}
				$item->internalSetCustomFieldData($fieldName, $customFieldData);
			}
		}
	}

	/**
	 * @param array<int, array<string, mixed>> $itemFieldValues
	 * @return array<int, array<string, string|string[]>>
	 */
	protected function getFormattedValuesMap(array $itemFieldValues, int $entityTypeId, array $itemExtraValuesMap): array
	{
		$customFields = $this->getCustomFields($entityTypeId);
		$formattableTypesFromDisplay = $this->getFormattableTypesFromDisplay();
		$formattableTypesFromExtra = $this->getFormattableTypesFromExtra();
		$itemFormattedValuesMap = [];
		$displayFields = [];
		foreach ($itemFieldValues as $itemId => $fieldValues)
		{
			foreach ($fieldValues as $fieldName => $rawValue)
			{
				if (!isset($customFields[$fieldName]))
				{
					continue;
				}

				$type = $customFields[$fieldName]['USER_TYPE_ID'] ?? '';
				if (isset($formattableTypesFromDisplay[$type]) && !isset($displayFields[$fieldName]))
				{
					$displayFields[$fieldName] = Field::createFromUserField($fieldName, $customFields[$fieldName])
						->setContext(Field::KANBAN_CONTEXT)
					;
				}
				elseif (isset($formattableTypesFromExtra[$type]))
				{
					$extraValue = $itemExtraValuesMap[$itemId][$fieldName] ?? null;
					$itemFormattedValuesMap[$itemId][$fieldName] = match ($type)
					{
						'employee' => $this->getFormattedEmployeeValue(
							$rawValue,
							$extraValue,
						),
						'enumeration' => $this->getFormattedEnumerationValue(
							$rawValue,
							$extraValue,
						),
						'crm_status' => $this->getFormattedCrmStatusValue($rawValue, $extraValue),
						'crm' => $this->getFormattedCrmValue($rawValue, $extraValue),
						'iblock_section', 'iblock_element' => $this->getFormattedNamedExtraValue(
							$rawValue,
							$extraValue,
						),
						'file' => $this->getFormattedFileValue($rawValue, $extraValue),
						default => $rawValue === null ? null : (string)$rawValue,
					};
				}
				elseif (is_array($rawValue))
				{
					$itemFormattedValuesMap[$itemId][$fieldName] = array_map(
						static fn(mixed $rawValueItem): ?string => $rawValueItem === null
							? null
							: (string)$rawValueItem
						,
						$rawValue,
					);
				}
				else
				{
					$itemFormattedValuesMap[$itemId][$fieldName] = $rawValue === null ? null : (string)$rawValue;
				}
			}
		}

		if (empty($displayFields))
		{
			return $itemFormattedValuesMap;
		}

		$displayOptions = new Options();
		$displayOptions->setShowOnlyText(true);

		$itemDisplayValues
			= (new Display($entityTypeId, $displayFields, $displayOptions))
				->setItems($itemFieldValues)
				->getAllValues()
		;
		foreach ($itemDisplayValues as $itemId => $displayValues)
		{
			foreach ($displayValues as $fieldName => $displayValue)
			{
				$itemFormattedValuesMap[$itemId][$fieldName] = $displayValue;
			}
		}

		foreach ($itemFieldValues as $itemId => $fieldValues)
		{
			foreach ($fieldValues as $fieldName => $rawValue)
			{
				if (
					!isset($displayFields[$fieldName], $customFields[$fieldName])
					|| !is_array($rawValue)
					|| !array_is_list($rawValue)
				)
				{
					continue;
				}

				$type = $customFields[$fieldName]['USER_TYPE_ID'] ?? '';
				if (!in_array($type, ['date', 'datetime'], true))
				{
					continue;
				}

				$itemFormattedValuesMap[$itemId][$fieldName] = $this->getFormattedMultipleDisplayValues(
					$entityTypeId,
					(int)$itemId,
					$fieldName,
					$customFields[$fieldName],
					$rawValue,
				);
			}
		}

		return $itemFormattedValuesMap;
	}

	protected function getFormattedMultipleDisplayValues(
		int $entityTypeId,
		int $itemId,
		string $fieldName,
		array $customField,
		array $rawValues,
	): array
	{
		$singleCustomField = $customField;
		$singleCustomField['MULTIPLE'] = 'N';

		$displayOptions = new Options();
		$displayOptions->setShowOnlyText(true);

		$displayField = Field::createFromUserField($fieldName, $singleCustomField)
			->setContext(Field::KANBAN_CONTEXT)
		;

		$items = [];
		$itemIdsByIndex = [];
		foreach ($rawValues as $index => $rawValue)
		{
			$displayItemId = max(1, $itemId) + (int)$index;
			$itemIdsByIndex[$index] = $displayItemId;
			$items[$displayItemId] = [$fieldName => $rawValue];
		}

		$displayValues = (new Display($entityTypeId, [$fieldName => $displayField], $displayOptions))
			->setItems($items)
			->getAllValues()
		;

		$result = [];
		foreach ($rawValues as $index => $rawValue)
		{
			$result[$index] = $displayValues[$itemIdsByIndex[$index]][$fieldName] ?? null;
		}

		return $result;
	}

	/**
	 * @return array<string, bool>
	 */
	protected function getFormattableTypesFromDisplay(): array
	{
		static $formattableTypesFromDisplay = null;
		if ($formattableTypesFromDisplay === null)
		{
			$formattableTypesFromDisplay = array_fill_keys(
				[
					'boolean',
					'money',
					'address',
					'datetime',
					'date',
				],
				true,
			);
		}

		return $formattableTypesFromDisplay;
	}

	/**
	 * @return array<string, bool>
	 */
	protected function getFormattableTypesFromExtra(): array
	{
		static $formattableTypesFromExtra = null;
		if ($formattableTypesFromExtra === null)
		{
			$formattableTypesFromExtra = array_fill_keys(
				[
					'employee',
					'enumeration',
					'crm_status',
					'crm',
					'iblock_section',
					'iblock_element',
					'file',
				],
				true,
			);
		}

		return $formattableTypesFromExtra;
	}

	/**
	 * @param array<int, array<string, mixed>> $itemFieldValues
	 * @return array<int, array<string, mixed>>
	 */
	protected function getExtraValuesMap(array $itemFieldValues, int $entityTypeId, array $customFieldDataSelect = []): array
	{
		if ($itemFieldValues === [])
		{
			return [];
		}

		$customFields = $this->getCustomFields($entityTypeId);
		$extraValuesByField = [];
		$formattedEnumerationValues = [];
		$employeeRawValues = [];
		$moneyRawValues = [];
		$addressRawValues = [];
		$crmRawValuesByField = [];
		$crmSelectByField = [];
		$enumerationRawValues = [];
		$crmStatusRawValuesByType = [];
		$iblockSectionRawValuesByIblock = [];
		$iblockElementRawValuesByIblock = [];
		$fileRawValues = [];

		foreach ($itemFieldValues as $fieldValues)
		{
			foreach ($fieldValues as $fieldName => $rawValue)
			{
				$field = $customFields[$fieldName] ?? null;
				if ($field === null)
				{
					continue;
				}

				$type = (string)($field['USER_TYPE_ID'] ?? '');
				$rawValues = is_array($rawValue) && array_is_list($rawValue) ? $rawValue : [$rawValue];
				$rawValues = array_values(array_filter($rawValues, static fn(mixed $value): bool => $value !== null && $value !== ''));
				if ($rawValues === [])
				{
					continue;
				}

				match ($type)
				{
					'employee' => array_push($employeeRawValues, ...$rawValues),
					'money' => array_push($moneyRawValues, ...$rawValues),
					'address' => array_push($addressRawValues, ...$rawValues),
					'crm' => $this->appendGroupedRawValues(
						$crmRawValuesByField,
						$this->getExtraValueMapKey($type, $field),
						$rawValues,
					),
					'enumeration' => array_push($enumerationRawValues, ...$rawValues),
					'crm_status' => $this->appendGroupedRawValues(
						$crmStatusRawValuesByType,
						(string)($field['SETTINGS']['ENTITY_TYPE'] ?? ''),
						$rawValues,
					),
					'iblock_section' => $this->appendGroupedRawValues(
						$iblockSectionRawValuesByIblock,
						(int)($field['SETTINGS']['IBLOCK_ID'] ?? 0),
						$rawValues,
					),
					'iblock_element' => $this->appendGroupedRawValues(
						$iblockElementRawValuesByIblock,
						(int)($field['SETTINGS']['IBLOCK_ID'] ?? 0),
						$rawValues,
					),
					'file' => array_push($fileRawValues, ...$rawValues),
					default => null,
				};

				if ($type === 'crm')
				{
					$this->appendCrmSelect(
						$crmSelectByField,
						$this->getExtraValueMapKey($type, $field),
						$customFieldDataSelect[$fieldName] ?? [],
					);
				}
			}
		}

		$extraValuesByField['employee'] = $this->getExtraEmployeeMap($employeeRawValues);
		$extraValuesByField['money'] = $this->loadMoneyExtraMap($moneyRawValues);
		$extraValuesByField['address'] = $this->loadAddressExtraMap($addressRawValues);
		$extraValuesByField['file'] = $this->loadFileMetadataMap($fileRawValues);
		$formattedEnumerationValues = $this->loadEnumerationFormattedValueMap($enumerationRawValues);

		foreach ($crmRawValuesByField as $mapKey => $rawValues)
		{
			$extraValuesByField[$mapKey] = $this->loadCrmExtraMap(
				$rawValues,
				$this->getSingleCrmEntityTypeIdFromMapKey((string)$mapKey),
				array_values(array_unique($crmSelectByField[$mapKey] ?? [])),
			);
		}
		foreach ($crmStatusRawValuesByType as $statusTypeId => $rawValues)
		{
			if ($statusTypeId === '')
			{
				continue;
			}
			$extraValuesByField['crm_status:' . $statusTypeId] = $this->loadCrmStatusExtraMap($statusTypeId, $rawValues);
		}
		foreach ($iblockSectionRawValuesByIblock as $iBlockId => $rawValues)
		{
			if ($iBlockId <= 0)
			{
				continue;
			}
			$extraValuesByField['iblock_section:' . $iBlockId] = $this->loadIblockSectionExtraMap($iBlockId, $rawValues);
		}
		foreach ($iblockElementRawValuesByIblock as $iBlockId => $rawValues)
		{
			if ($iBlockId <= 0)
			{
				continue;
			}
			$extraValuesByField['iblock_element:' . $iBlockId] = $this->loadIblockElementExtraMap($iBlockId, $rawValues);
		}

		$itemExtraValuesMap = [];
		foreach ($itemFieldValues as $itemId => $fieldValues)
		{
			foreach ($fieldValues as $fieldName => $rawValue)
			{
				$field = $customFields[$fieldName] ?? null;
				if ($field === null)
				{
					continue;
				}

				$type = (string)($field['USER_TYPE_ID'] ?? '');
				$mapKey = $this->getExtraValueMapKey($type, $field);
				$valueMap = $extraValuesByField[$mapKey] ?? null;

				if ($type === 'enumeration')
				{
					$valueMap = $formattedEnumerationValues;
				}

				if ($valueMap === null)
				{
					continue;
				}

				if (is_array($rawValue) && array_is_list($rawValue))
				{
					foreach ($rawValue as $rawValueItem)
					{
						$itemExtraValuesMap[$itemId][$fieldName][] = $valueMap[$this->normalizeExtraValueIndex($rawValueItem)] ?? null;
					}
				}
				else
				{
					$itemExtraValuesMap[$itemId][$fieldName] = $valueMap[$this->normalizeExtraValueIndex($rawValue)] ?? null;
				}
			}
		}

		return $itemExtraValuesMap;
	}

	protected function getExtraValueMapKey(string $type, array $field): string
	{
		return match ($type)
		{
			'crm' => $type . ':' . ($this->getSingleCrmEntityTypeId($field) ?? 0),
			'crm_status' => $type . ':' . (string)($field['SETTINGS']['ENTITY_TYPE'] ?? ''),
			'iblock_section', 'iblock_element' => $type . ':' . (int)($field['SETTINGS']['IBLOCK_ID'] ?? 0),
			default => $type,
		};
	}

	protected function normalizeExtraValueIndex(mixed $rawValue): string|int
	{
		if ($rawValue instanceof File)
		{
			return $rawValue->getId() ?? 0;
		}

		if ($rawValue instanceof ItemId)
		{
			return sprintf(
				'%s_%d',
				\CCrmOwnerTypeAbbr::ResolveByTypeID($rawValue->getEntityType()->getId()),
				$rawValue->getId(),
			);
		}

		if (is_int($rawValue))
		{
			return $rawValue;
		}

		if (is_float($rawValue))
		{
			return (string)$rawValue;
		}

		if (is_bool($rawValue))
		{
			return (int)$rawValue;
		}

		return (string)$rawValue;
	}

	protected function normalizeFileValue(mixed $value): mixed
	{
		if ($value instanceof File)
		{
			return $value->getId();
		}

		if (is_array($value) && array_is_list($value))
		{
			return array_map(
				fn(mixed $item): mixed => $this->normalizeFileValue($item),
				$value,
			);
		}

		return $value;
	}

	protected function appendGroupedRawValues(array &$target, string|int $key, array $rawValues): void
	{
		$target[$key] ??= [];
		array_push($target[$key], ...$rawValues);
	}

	protected function appendCrmSelect(array &$selectByField, string $mapKey, array $select): void
	{
		if ($select === [] || (array_key_exists($mapKey, $selectByField) && $selectByField[$mapKey] === []))
		{
			$selectByField[$mapKey] = [];

			return;
		}

		$selectByField[$mapKey] = array_merge($selectByField[$mapKey] ?? [], $select);
	}

	protected function getSingleCrmEntityTypeId(array $field): ?int
	{
		$entityTypeIds = [];
		foreach (($field['SETTINGS'] ?? []) as $entityTypeName => $isEnabled)
		{
			if (!($isEnabled === true || $isEnabled === 'Y'))
			{
				continue;
			}

			$entityTypeId = \CCrmOwnerType::ResolveID((string)$entityTypeName);
			if ($entityTypeId > 0)
			{
				$entityTypeIds[$entityTypeId] = true;
			}
		}

		if (count($entityTypeIds) !== 1)
		{
			return null;
		}

		return (int)array_key_first($entityTypeIds);
	}

	protected function getSingleCrmEntityTypeIdFromMapKey(string $mapKey): ?int
	{
		[$type, $entityTypeId] = array_pad(explode(':', $mapKey, 2), 2, '');
		if ($type !== 'crm')
		{
			return null;
		}

		$entityTypeId = (int)$entityTypeId;

		return $entityTypeId > 0 ? $entityTypeId : null;
	}

	protected function getCustomFields(int $entityTypeId): array
	{
		static $customFieldsMapByEntityTypeId = [];
		$cacheKey = $entityTypeId . ':' . ($this->accessUserId ?? 'context');
		if (!isset($customFieldsMapByEntityTypeId[$cacheKey]))
		{
			$customFieldEntityId = \CCrmOwnerType::ResolveUserFieldEntityID($entityTypeId);
			$customFields = $this->createUserTypeManager($customFieldEntityId)->GetAbstractFields([
				'skipUserFieldVisibilityCheck' => true,
			]);

			$customFieldsMapByEntityTypeId[$cacheKey] = $this->filterVisibleFields($customFields, $this->accessUserId);
		}

		return $customFieldsMapByEntityTypeId[$cacheKey];
	}

	protected function filterVisibleFields(array $fields, ?int $accessUserId): array
	{
		return VisibilityManager::getVisibleUserFields($fields, $accessUserId);
	}

	protected function createUserTypeManager(string $customFieldEntityId): \CCrmUserType
	{
		return new \CCrmUserType(Application::getUserTypeManager(), $customFieldEntityId);
	}

	/**
	 * @param int[] $rawValues
	 * @return array<int, Employee>
	 */
	protected function getExtraEmployeeMap(array $rawValues): array
	{
		$userIds = array_map(static fn ($rawValue): int => (int)$rawValue, $rawValues);

		return (new EmployeeLoader())->loadByIds($userIds);
	}

	/**
	 * @param mixed[] $rawValues
	 * @return array<string, Money>
	 */
	protected function loadMoneyExtraMap(array $rawValues): array
	{
		$rawValues = array_values(array_unique(array_filter($rawValues, static fn(mixed $value): bool => is_string($value) && $value !== '')));
		if ($rawValues === [])
		{
			return [];
		}

		$currencies = Editor::getListCurrency();
		$result = [];
		foreach ($rawValues as $rawValue)
		{
			[$amount, $currencyId] = MoneyType::unFormatFromDb($rawValue);
			$result[$rawValue] = new Money(
				amount: $amount === '' ? null : (float)$amount,
				currencyId: $currencyId !== '' ? $currencyId : null,
				currencyFullName: $currencyId !== '' ? ($currencies[$currencyId]['NAME'] ?? null) : null,
			);
		}

		return $result;
	}

	/**
	 * @param mixed[] $rawValues
	 * @return array<string, Address>
	 */
	protected function loadAddressExtraMap(array $rawValues): array
	{
		$parsedByRawValue = [];
		foreach ($rawValues as $rawValue)
		{
			if (!is_string($rawValue) || $rawValue === '')
			{
				continue;
			}

			$parsed = $this->parseAddressRawValue($rawValue);
			$parsedByRawValue[$rawValue] = $parsed;
		}

		$result = [];

		foreach ($parsedByRawValue as $rawValue => $parsed)
		{
			$addressFields = $parsed['addressFields'] ?? [];
			$result[$rawValue] = new Address(
				locationId: $parsed['locationId'],
				latitude: $parsed['latitude'],
				longitude: $parsed['longitude'],
				country: $addressFields[LocationFieldType::COUNTRY] ?? null,
				city: $addressFields[LocationFieldType::LOCALITY] ?? null,
				postalCode: $addressFields[LocationFieldType::POSTAL_CODE] ?? null,
				address1: $addressFields[LocationFieldType::ADDRESS_LINE_1] ?? $parsed['addressText'],
				address2: $addressFields[LocationFieldType::ADDRESS_LINE_2] ?? null,
			);
		}

		return $result;
	}

	/**
	 * @param mixed[] $rawValues
	 * @return array<string, Item>
	 */
	protected function loadCrmExtraMap(array $rawValues, ?int $singleEntityTypeId = null, array $select = []): array
	{
		$uniqueRawValues = [];
		foreach ($rawValues as $rawValue)
		{
			if ($rawValue === null || $rawValue === '')
			{
				continue;
			}

			$uniqueRawValues[$this->normalizeExtraValueIndex($rawValue)] = $rawValue;
		}
		$rawValues = array_values($uniqueRawValues);
		if ($rawValues === [])
		{
			return [];
		}

		$rawValuesByEntityType = [];
		foreach ($rawValues as $rawValue)
		{
			$parsed = $this->parseCrmValue($rawValue, $singleEntityTypeId);
			if ($parsed === null)
			{
				continue;
			}

			$rawValuesByEntityType[$parsed['entityTypeId']][(int)$parsed['entityId']] = $this->normalizeExtraValueIndex($rawValue);
		}

		$itemsByRawValue = [];
		foreach ($rawValuesByEntityType as $entityTypeId => $rawValueMap)
		{
			$entityType = EntityType::fromId((int)$entityTypeId);
			if ($entityType === null)
			{
				continue;
			}

			$itemSelect = $this->getCrmExtraItemSelect($select, (int)$entityTypeId);
			foreach (array_chunk(array_keys($rawValueMap), self::BATCH_SIZE) as $idsChunk)
			{
				foreach ($this->loadCrmItemsByIds($entityType, $idsChunk, $itemSelect) as $item)
				{
					$itemId = $item->getId();
					if ($itemId !== null && isset($rawValueMap[$itemId]))
					{
						$itemsByRawValue[$rawValueMap[$itemId]] = $item;
					}
				}
			}
		}

		return $itemsByRawValue;
	}

	/** @param int[] $ids */
	protected function loadCrmItemsByIds(EntityType $entityType, array $ids, ItemSelect $select): iterable
	{
		return ItemProvider::forEntityType($entityType)
			->withAccessCheck($this->accessUserId, AccessMode::Restricted)
			->getByIds($ids, $select)
		;
	}

	private function getCrmExtraItemSelect(array $select, int $entityTypeId): ItemSelect
	{
		$fields = ['id'];
		if ($select === [] || in_array('formattedValue', $select, true) || in_array('extra', $select, true))
		{
			array_push($fields, ...$this->getCrmExtraTitleSourceFields($entityTypeId));
		}

		if ($select === [] || in_array('extra', $select, true))
		{
			array_push($fields, ...$this->getCrmExtraUrlSourceFields($entityTypeId));
		}

		return new ItemSelect(...array_values(array_unique($fields)));
	}

	/**
	 * @return string[]
	 */
	private function getCrmExtraTitleSourceFields(int $entityTypeId): array
	{
		if ($entityTypeId === EntityType::contact()->getId())
		{
			return ['name', 'lastName', 'secondName'];
		}

		return ['title'];
	}

	/**
	 * @return string[]
	 */
	private function getCrmExtraUrlSourceFields(int $entityTypeId): array
	{
		if (EntityTypeSettings::of(EntityType::fromId($entityTypeId))->isCategoriesSupported())
		{
			return ['categoryId'];
		}

		return [];
	}

	/**
	 * @param mixed[] $rawValues
	 * @return array<int|string, string>
	 */
	protected function loadEnumerationFormattedValueMap(array $rawValues): array
	{
		$enumIds = array_values(array_unique(array_map(static fn(mixed $value): int => (int)$value, $rawValues)));
		if ($enumIds === [])
		{
			return [];
		}

		$result = [];
		foreach (array_chunk($enumIds, self::BATCH_SIZE) as $enumIdsChunk)
		{
			foreach ($this->loadEnumerationRowsByIds($enumIdsChunk) as $enumId => $enum)
			{
				$result[(int)$enumId] = (string)($enum['VALUE'] ?? '');
			}
		}

		return $result;
	}

	/** @param int[] $ids */
	protected function loadEnumerationRowsByIds(array $ids): array
	{
		$this->enumerationBroker ??= new Enumeration();

		return $this->enumerationBroker->getBunchByIds($ids);
	}

	/**
	 * @param mixed[] $rawValues
	 * @return array<int, FileMetadata>
	 */
	protected function loadFileMetadataMap(array $rawValues): array
	{
		$fileIds = [];
		foreach ($rawValues as $rawValue)
		{
			$fileId = $this->normalizeFileId($rawValue);
			if ($fileId !== null)
			{
				$fileIds[$fileId] = $fileId;
			}
		}

		$result = [];
		foreach (array_chunk(array_values($fileIds), self::BATCH_SIZE) as $fileIdsChunk)
		{
			foreach ($this->fetchFileMetadataChunk($fileIdsChunk) as $row)
			{
				$fileId = (int)($row['ID'] ?? 0);
				if ($fileId <= 0)
				{
					continue;
				}

				$originalName = (string)($row['ORIGINAL_NAME'] ?? '');
				$fileName = (string)($row['FILE_NAME'] ?? '');
				$result[$fileId] = new FileMetadata($originalName !== '' ? $originalName : $fileName);
			}
		}

		return $result;
	}

	protected function fetchFileMetadataChunk(array $ids): iterable
	{
		return FileTable::query()
			->setSelect(['ID', 'ORIGINAL_NAME', 'FILE_NAME'])
			->whereIn('ID', $ids)
			->exec()
		;
	}

	private function normalizeFileId(mixed $value): ?int
	{
		if ($value instanceof File)
		{
			$value = $value->getId();
		}

		if (is_int($value))
		{
			return $value > 0 ? $value : null;
		}

		if (is_string($value) && preg_match('/\\A[0-9]+\\z/', $value) === 1)
		{
			$value = (int)$value;

			return $value > 0 ? $value : null;
		}

		return null;
	}

	/**
	 * @param mixed[] $rawValues
	 * @return array<string, CrmStatus>
	 */
	protected function loadCrmStatusExtraMap(string $statusTypeId, array $rawValues): array
	{
		$statusIds = array_values(array_unique(array_map(static fn(mixed $value): string => (string)$value, $rawValues)));
		if ($statusTypeId === '' || $statusIds === [])
		{
			return [];
		}

		$result = [];
		$iterator = StatusTable::query()
			->setSelect(['STATUS_ID', 'NAME', 'SORT'])
			->where('ENTITY_ID', $statusTypeId)
			->whereIn('STATUS_ID', $statusIds)
			->exec()
		;
		while ($row = $iterator->fetch())
		{
			$status = new CrmStatus(
				id: (string)$row['STATUS_ID'],
				name: (string)($row['NAME'] ?? ''),
				sort: isset($row['SORT']) ? (int)$row['SORT'] : null,
			);
			$result[$status->getId()] = $status;
		}

		return $result;
	}

	/**
	 * @param mixed[] $rawValues
	 * @return array<int, IblockSection>
	 */
	protected function loadIblockSectionExtraMap(int $iBlockId, array $rawValues): array
	{
		if ($iBlockId <= 0 || !Loader::includeModule('iblock') || !class_exists(\CIBlockSection::class))
		{
			return [];
		}

		$ids = array_values(array_unique(array_map(static fn(mixed $value): int => (int)$value, $rawValues)));
		if ($ids === [])
		{
			return [];
		}

		$result = [];
		foreach (array_chunk($ids, 500) as $chunk)
		{
			$filter = [
				'IBLOCK_ID' => $iBlockId,
				'ID' => $chunk,
				'CHECK_PERMISSIONS' => 'Y',
				'MIN_PERMISSION' => 'R',
			];
			if ($this->accessUserId !== null)
			{
				$filter['PERMISSIONS_BY'] = $this->accessUserId;
			}

			$iterator = \CIBlockSection::GetList([], $filter, false, ['ID', 'NAME']);
			while ($row = $iterator->fetch())
			{
				$section = new IblockSection(
					id: (int)$row['ID'],
					name: isset($row['NAME']) ? (string)$row['NAME'] : null,
				);
				$result[$section->getId()] = $section;
			}
		}

		return $result;
	}

	/**
	 * @param mixed[] $rawValues
	 * @return array<int, IblockElement>
	 */
	protected function loadIblockElementExtraMap(int $iBlockId, array $rawValues): array
	{
		if ($iBlockId <= 0 || !Loader::includeModule('iblock') || !class_exists(\CIBlockElement::class))
		{
			return [];
		}

		$ids = array_values(array_unique(array_map(static fn(mixed $value): int => (int)$value, $rawValues)));
		if ($ids === [])
		{
			return [];
		}

		$result = [];
		foreach (array_chunk($ids, 500) as $chunk)
		{
			$filter = [
				'IBLOCK_ID' => $iBlockId,
				'ID' => $chunk,
				'CHECK_PERMISSIONS' => 'Y',
				'MIN_PERMISSION' => 'R',
			];
			if ($this->accessUserId !== null)
			{
				$filter['PERMISSIONS_BY'] = $this->accessUserId;
			}

			$iterator = \CIBlockElement::GetList([], $filter, false, false, ['ID', 'NAME']);
			while ($row = $iterator->fetch())
			{
				$element = new IblockElement(
					id: (int)$row['ID'],
					name: isset($row['NAME']) ? (string)$row['NAME'] : null,
				);
				$result[$element->getId()] = $element;
			}
		}

		return $result;
	}

	protected function parseCrmValue(mixed $rawValue, ?int $singleEntityTypeId = null): ?array
	{
		if ($rawValue instanceof ItemId)
		{
			return [
				'entityTypeId' => $rawValue->getEntityType()->getId(),
				'entityId' => $rawValue->getId(),
			];
		}

		if (
			$singleEntityTypeId !== null
			&& (
				(is_int($rawValue) && $rawValue > 0)
				|| (is_string($rawValue) && preg_match('/\\A[0-9]+\\z/', $rawValue) === 1 && (int)$rawValue > 0)
			)
		)
		{
			return [
				'entityTypeId' => $singleEntityTypeId,
				'entityId' => (int)$rawValue,
			];
		}

		if (!is_string($rawValue) || $rawValue === '')
		{
			return null;
		}

		[$entityTypeCode, $entityId] = array_pad(explode('_', $rawValue, 2), 2, '');
		if ($entityTypeCode === '' || $entityId === '')
		{
			return null;
		}

		$entityTypeId = \CCrmOwnerType::ResolveID(\CCrmOwnerTypeAbbr::ResolveName($entityTypeCode));
		if ($entityTypeId <= 0)
		{
			return null;
		}

		return [
			'entityTypeId' => $entityTypeId,
			'entityId' => (int)$entityId,
		];
	}

	protected function parseAddressRawValue(string $rawValue): array
	{
		[$addressText, $coordinates, $locationId] = array_pad(explode('|', $rawValue, 3), 3, '');
		[$latitude, $longitude] = array_pad(explode(';', $coordinates, 2), 2, '');

		if ($locationId === '')
		{
			try
			{
				$address = LocationAddress::fromJson($rawValue);

				return [
					'locationId' => $address->getId() ?: null,
					'latitude' => $address->getLatitude() !== null ? (float)$address->getLatitude() : null,
					'longitude' => $address->getLongitude() !== null ? (float)$address->getLongitude() : null,
					'addressText' => null,
					'addressFields' => [
						LocationFieldType::COUNTRY => $address->getFieldValue(LocationFieldType::COUNTRY),
						LocationFieldType::LOCALITY => $address->getFieldValue(LocationFieldType::LOCALITY),
						LocationFieldType::POSTAL_CODE => $address->getFieldValue(LocationFieldType::POSTAL_CODE),
						LocationFieldType::ADDRESS_LINE_1 => $address->getFieldValue(LocationFieldType::ADDRESS_LINE_1),
						LocationFieldType::ADDRESS_LINE_2 => $address->getFieldValue(LocationFieldType::ADDRESS_LINE_2),
					],
				];
			}
			catch (\Throwable)
			{
			}
		}

		return [
			'locationId' => $locationId !== '' ? (int)$locationId : null,
			'latitude' => $latitude !== '' ? (float)$latitude : null,
			'longitude' => $longitude !== '' ? (float)$longitude : null,
			'addressText' => $addressText !== '' ? $addressText : null,
			'addressFields' => [],
		];
	}

	protected function getFormattedEmployeeValue(mixed $rawValue, null|array|Employee $extraValue): string|array
	{

		if (is_array($rawValue) && array_is_list($rawValue))
		{
			$result = [];
			foreach ($rawValue as $index => $rawValueItem)
			{
				$result[$index] = $this->getFormattedEmployeeValue($rawValueItem, $extraValue[$index] ?? null);
			}

			return $result;
		}

		if ($extraValue instanceof Employee)
		{
			return (string)$extraValue->getFormattedName();
		}

		return '';
	}

	protected function getFormattedCrmStatusValue(mixed $rawValue, mixed $extraValue): string|array
	{
		return $this->getFormattedDtoNameValue(
			$rawValue,
			$extraValue,
			static fn(CrmStatus $status): string => (string)$status->getName(),
		);
	}

	protected function getFormattedEnumerationValue(mixed $rawValue, mixed $extraValue): string|array
	{
		if (is_array($rawValue) && array_is_list($rawValue))
		{
			$result = [];
			foreach ($rawValue as $index => $rawValueItem)
			{
				$result[$index] = $this->getFormattedEnumerationValue($rawValueItem, $extraValue[$index] ?? null);
			}

			return $result;
		}

		return is_string($extraValue) ? $extraValue : '';
	}

	protected function getFormattedCrmValue(mixed $rawValue, mixed $extraValue): string|array
	{
		if (is_array($rawValue) && array_is_list($rawValue))
		{
			$result = [];
			foreach ($rawValue as $index => $rawValueItem)
			{
				$result[$index] = $this->getFormattedCrmValue($rawValueItem, $extraValue[$index] ?? null);
			}

			return $result;
		}

		if ($extraValue instanceof Item)
		{
			return $extraValue->getCaption();
		}

		return '';
	}

	protected function getFormattedNamedExtraValue(mixed $rawValue, mixed $extraValue): string|array
	{
		return $this->getFormattedDtoNameValue(
			$rawValue,
			$extraValue,
			static fn(object $value): string => method_exists($value, 'getName') ? (string)$value->getName() : '',
		);
	}

	protected function getFormattedDtoNameValue(mixed $rawValue, mixed $extraValue, callable $resolver): string|array
	{
		if (is_array($rawValue) && array_is_list($rawValue))
		{
			$result = [];
			foreach ($rawValue as $index => $rawValueItem)
			{
				$result[$index] = $this->getFormattedDtoNameValue($rawValueItem, $extraValue[$index] ?? null, $resolver);
			}

			return $result;
		}

		if (is_object($extraValue))
		{
			return $resolver($extraValue);
		}

		return '';
	}

	protected function getFormattedFileValue(mixed $rawValue, mixed $extraValue): string|array|null
	{
		if (is_array($rawValue) && array_is_list($rawValue))
		{
			$result = [];
			foreach ($rawValue as $index => $rawValueItem)
			{
				$result[$index] = $this->getFormattedFileValue(
					$rawValueItem,
					is_array($extraValue) ? ($extraValue[$index] ?? null) : null,
				);
			}

			return $result;
		}

		return $extraValue instanceof FileMetadata ? $extraValue->getName() : null;
	}
}
