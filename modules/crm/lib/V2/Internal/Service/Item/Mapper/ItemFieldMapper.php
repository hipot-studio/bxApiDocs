<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Item\Mapper;

use Bitrix\Crm\Item as LegacyItem;
use Bitrix\Crm\UtmTable;
use Bitrix\Crm\V2\Internal\Service\Item\CustomFieldDescriptor;
use Bitrix\Crm\V2\Internal\Service\Item\CustomFieldRegistry;
use Bitrix\Crm\V2\Internal\Service\Item\FieldDescriptor;
use Bitrix\Crm\V2\Internal\Service\Item\FieldRegistry;
use Bitrix\Crm\V2\Internal\Service\Item\FieldType;
use Bitrix\Crm\V2\Internal\Service\Item\LegacyFieldNameResolver;
use Bitrix\Crm\V2\Public\Entity\File;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Crm\V2\Public\ItemId;
use Bitrix\Crm\V2\Public\Entity\Item\CompanyBinding;
use Bitrix\Crm\V2\Public\Entity\Item\CompanyBindingCollection;
use Bitrix\Crm\V2\Public\Entity\Item\ContactBinding;
use Bitrix\Crm\V2\Public\Entity\Item\ContactBindingCollection;
use Bitrix\Crm\V2\Public\Entity\Item\Item;
use Bitrix\Crm\V2\Public\Entity\Item\AbstractMultifieldValue;
use Bitrix\Crm\V2\Public\Entity\Item\PhoneValue;
use Bitrix\Crm\V2\Public\Entity\Item\Utm;
use Bitrix\Crm\V2\Public\Entity\Item\ProductRow;
use Bitrix\Crm\V2\Public\Entity\Item\ProductRowCollection;
use Bitrix\Main\ORM\Objectify\EntityObject;

/**
 * Maps V2 Item fields to/from legacy Item fields.
 * @internal
 */
class ItemFieldMapper
{
	/**
	 * Copy ALL non-null fields from V2 Item to legacy Item (for Add).
	 */
	public static function copyToLegacy(Item $v2Item, LegacyItem $legacyItem): void
	{
		$registry = FieldRegistry::getInstance($v2Item->getEntityType());

		foreach ($registry->getFields() as $descriptor)
		{
			if ($descriptor->readonly)
			{
				continue;
			}

			$value = self::getV2FieldValue($v2Item, $descriptor);
			if ($value === null)
			{
				continue;
			}

			self::setLegacyFieldValue($legacyItem, $descriptor, $value, $registry);
		}

		self::copyCustomFieldsToLegacy($v2Item, $legacyItem);
		self::copyParentIdsToLegacy($v2Item, $legacyItem);
	}

	/**
	 * Copy only CHANGED fields from V2 Item to legacy Item (for Update).
	 */
	public static function copyChangedToLegacy(Item $v2Item, LegacyItem $legacyItem): void
	{
		$registry = FieldRegistry::getInstance($v2Item->getEntityType());
		$changedFields = $v2Item->getChangedFieldNames();
		$visibleCustomFields = null;
		$customFieldDescriptors = null;

		foreach ($changedFields as $camelName)
		{
			// Custom fields (UF_*)
			if (str_starts_with($camelName, 'UF_'))
			{
				if ($visibleCustomFields === null)
				{
					$visibleCustomFields = static::getVisibleCustomFields($v2Item->getEntityType());
				}
				if (isset($visibleCustomFields[$camelName]))
				{
					$customFieldDescriptors ??=
						CustomFieldRegistry::getInstance()->getEntityDescriptorsMap($v2Item->getEntityType());
					self::setLegacyCustomField(
						$legacyItem,
						$camelName,
						$v2Item->getCustomField($camelName),
						$customFieldDescriptors[$camelName] ?? null,
					);
				}

				continue;
			}

			// Parent reference fields — markChanged stores them as 'parentId_<typeId>'.
			if (str_starts_with($camelName, 'parentId_'))
			{
				$parentTypeId = (int)substr($camelName, strlen('parentId_'));
				$parentId = $v2Item->getParentId(EntityType::fromId($parentTypeId));
				// A null parent is an explicit detach: legacy treats an empty PARENT_ID_<typeId> as
				// "unbind" ({@see ParentFieldManager::saveParentRelationsForIdentifier()}), so send 0.
				$legacyItem->set('PARENT_ID_' . $parentTypeId, $parentId ?? 0);

				continue;
			}

			$descriptor = $registry->getField($camelName);
			if ($descriptor === null || $descriptor->readonly)
			{
				continue;
			}

			$value = self::getV2FieldValue($v2Item, $descriptor);
			self::setLegacyFieldValue($legacyItem, $descriptor, $value, $registry);
		}
	}

	/**
	 * Finds a field that the item writes but its target category disables.
	 *
	 * The category is applied only to a clone: mapping must not partially change the real item before
	 * the command is refused. UTM and multifields use several legacy field names, so their check has
	 * to use the same legacy representation as the write mapper.
	 */
	public static function findChangedFieldDisabledInCategory(Item $v2Item, LegacyItem $legacyItem): ?string
	{
		$registry = FieldRegistry::getInstance($v2Item->getEntityType());
		$categoryItem = clone $legacyItem;
		$categoryDescriptor = $registry->getField(Item::categoryId);
		if ($categoryDescriptor !== null && in_array(Item::categoryId, $v2Item->getChangedFieldNames(), true))
		{
			$categoryItem->set($categoryDescriptor->ormName, $v2Item->getCategoryId());
		}

		foreach ($v2Item->getChangedFieldNames() as $fieldName)
		{
			if ($fieldName === Item::categoryId)
			{
				continue;
			}

			foreach (LegacyFieldNameResolver::resolve($fieldName, $registry) as $legacyFieldName)
			{
				if ($categoryItem->isFieldDisabled($legacyFieldName))
				{
					return $fieldName;
				}
			}
		}

		return null;
	}

	/**
	 * Sync field values from legacy Item back to V2 Item (after Operation).
	 * Uses internalSet to avoid triggering change tracking.
	 *
	 * Distinguishes "field is not loaded in legacy" (skip — keep current V2 state)
	 * from "field is loaded and value is null" (sync null — Operation cleared it).
	 */
	public static function syncFromLegacy(LegacyItem $legacyItem, Item $v2Item): void
	{
		$registry = FieldRegistry::getInstance($v2Item->getEntityType());

		// Scalar copy (shared with {@see syncFromOrm()}).
		self::copyScalarFields(
			$v2Item,
			$registry,
			fn(string $ormName): bool => $legacyItem->hasField($ormName),
			fn(string $ormName): mixed => $legacyItem->get($ormName),
		);

		// Multifields are not on the legacy row directly — they live inside the FM bag and
		// need per-type extraction. Reverse conversion of ProductRow/Bindings is intentionally
		// skipped (handled by Loaders on the read path; not needed on the write-back path).
		foreach ($registry->getFields() as $descriptor)
		{
			if ($descriptor->type === FieldType::MultifieldCollection)
			{
				$value = self::extractMultifieldFromLegacy($legacyItem, $descriptor->name, $registry);
				if ($value !== null)
				{
					$v2Item->internalSet($descriptor->name, $value);
				}

				continue;
			}

			// Observers are exposed by legacy Item via OBSERVER_IDS (`Factory\Deal`) or
			// OBSERVERS (dynamic). We don't go through the Loader on the write-back path —
			// Operation has already populated the legacy field; just copy it across.
			if ($descriptor->type === FieldType::ObserverCollection)
			{
				if (!$legacyItem->hasField($descriptor->ormName))
				{
					continue;
				}

				$ids = self::convertToArray($legacyItem->get($descriptor->ormName), 'USER_ID');
				if ($ids !== null)
				{
					$v2Item->internalSet($descriptor->name, $ids);
				}
			}
		}

		self::syncCustomFieldsFromLegacy($legacyItem, $v2Item);
	}

	/**
	 * Hydrate $item from a raw ORM EntityObject without going through the legacy Item layer.
	 * Used by V2 Provider read-path (Repository → EntityObject → Item).
	 *
	 * Reads only scalar fields known to {@see FieldRegistry}. Relation collections
	 * (productRows, multifields, contactBindings, companyBindings) and Utm are intentionally
	 * left null — they are loaded by separate Loader services and merged onto the Item
	 * afterwards.
	 *
	 * @param EntityObject $eo Result of {@see DataManager::getByPrimary()->fetchObject()} or similar.
	 * @param Item $item Target V2 Item (must match $eo's entity type).
	 * @param FieldRegistry $registry Registry of $item's entity type. Resolved by the caller once
	 *                                per read and reused for the whole batch of items.
	 * @param ?array $selectedOrmFields If non-null, only fields whose ORM name is in this list are
	 *                                  copied. Pass the same select that was used to query $eo.
	 *                                  Pass null — or a list containing `'*'` — to copy every
	 *                                  Registry field that the EO actually has.
	 */
	public static function syncFromOrm(
		EntityObject $eo,
		Item $item,
		FieldRegistry $registry,
		?array $selectedOrmFields = null,
	): void
	{
		$selectedOrmNames = ($selectedOrmFields === null || in_array('*', $selectedOrmFields, true))
			? null
			: $selectedOrmFields
		;

		self::copyScalarFields(
			$item,
			$registry,
			fn(string $ormName): bool => $eo->entity->hasField($ormName),
			fn(string $ormName): mixed => $eo->get($ormName),
			$selectedOrmNames,
		);
	}

	/**
	 * Hydrate the requested custom fields (`UF_*`) from a raw ORM EntityObject, typing each value
	 * by its {@see CustomFieldDescriptor} per the UF typing matrix (MAP-01). Values land in the
	 * Item's custom-field bag through {@see Item::internalSet()} - no change tracking.
	 *
	 * The caller must pass only UF that were part of the fetch select (invariant: ufNames is a
	 * subset of the select). The per-name `hasField()` guard below checks the entity schema, not
	 * loadedness: a declared-but-unselected UF would still lazy-load on `$eo->get()`. It only skips a
	 * name the type does not declare as a UF at all.
	 *
	 * @param string[] $ufNames raw `UF_*` names to hydrate (already resolved against the entity type)
	 * @param array<string, CustomFieldDescriptor> $descriptors descriptor map of the entity type,
	 *        resolved by the caller once per read and reused for the whole batch of items
	 */
	public static function syncCustomFieldsFromOrm(
		EntityObject $eo,
		Item $item,
		array $ufNames,
		array $descriptors,
	): void
	{
		foreach ($ufNames as $ufName)
		{
			$descriptor = $descriptors[$ufName] ?? null;
			if ($descriptor === null || !$eo->entity->hasField($ufName))
			{
				continue;
			}

			$item->internalSet($ufName, self::castCustomFieldValue($eo->get($ufName), $descriptor));
		}
	}

	/**
	 * Type a raw ORM custom-field value per the UF typing matrix (MAP-01). Multiple UF map to a
	 * typed list; unresolved file / crm elements are dropped from that list.
	 */
	private static function castCustomFieldValue(mixed $rawValue, CustomFieldDescriptor $descriptor): mixed
	{
		if ($rawValue === null)
		{
			return null;
		}

		if (!$descriptor->isMultiple)
		{
			return self::castCustomFieldScalar($rawValue, $descriptor);
		}

		$result = [];
		foreach (is_array($rawValue) ? $rawValue : [$rawValue] as $element)
		{
			$typed = self::castCustomFieldScalar($element, $descriptor);
			// null means "no value" (empty element) or an unresolved file/crm ref - drop it.
			if ($typed !== null)
			{
				$result[] = $typed;
			}
		}

		return $result;
	}

	/**
	 * Type a single raw custom-field value by its `USER_TYPE_ID` (MAP-01). `date`/`datetime` come
	 * back from ORM already as {@see \Bitrix\Main\Type\Date}/{@see \Bitrix\Main\Type\DateTime}
	 * objects; `money`/`address` and any unlisted type stay raw.
	 */
	private static function castCustomFieldScalar(mixed $value, CustomFieldDescriptor $descriptor): mixed
	{
		if ($value === null)
		{
			return null;
		}

		return match ($descriptor->type)
		{
			'integer', 'employee', 'iblock_section', 'iblock_element', 'enumeration' => (int)$value,
			'double' => (float)$value,
			'boolean' => (bool)$value,
			'string', 'url', 'crm_status' => (string)$value,
			'file' => self::castFileValue($value),
			'crm' => self::castCrmValue($value, $descriptor),
			default => $value,
		};
	}

	/**
	 * Normalize a raw file id to a {@see File} value object, mapping a non-positive / non-numeric
	 * id to `null` (an absent file is `null`, never `File::fromId(0)`).
	 */
	private static function castFileValue(mixed $value): ?File
	{
		$id = is_numeric($value) ? (int)$value : 0;

		return $id > 0 ? File::fromId($id) : null;
	}

	/**
	 * Parse a raw crm-UF projection into an {@see ItemId}: `<abbr>_<id>` for multi-type fields,
	 * or a plain id when the descriptor declares exactly one entity type.
	 */
	private static function castCrmValue(
		mixed $value,
		CustomFieldDescriptor $descriptor,
	): ?ItemId
	{
		$entityTypesId = $descriptor->entityTypesId ?? [];
		if (
			count($entityTypesId) === 1
			&& (is_int($value) || (is_string($value) && preg_match('/^[0-9]+$/D', $value) === 1))
		)
		{
			$entityId = (int)$value;
			$entityTypeId = (int)array_values($entityTypesId)[0];

			if ($entityId > 0 && EntityType::isValid($entityTypeId))
			{
				return new ItemId(EntityType::fromId($entityTypeId), $entityId);
			}
		}

		if (!is_string($value) || !str_contains($value, '_'))
		{
			return null;
		}

		[$prefix, $rawId] = explode('_', $value, 2);
		$entityTypeId = (int)\CCrmOwnerTypeAbbr::ResolveTypeID($prefix);
		$entityId = (int)$rawId;
		if ($entityId <= 0 || !EntityType::isValid($entityTypeId))
		{
			return null;
		}

		return new ItemId(EntityType::fromId($entityTypeId), $entityId);
	}

	/**
	 * Copy scalar values onto $target, walking the columns the caller asked for and taking each
	 * descriptor from the registry's reverse index ({@see FieldRegistry::getScalarFieldsByOrmName()}).
	 * The cost follows the size of the select, not the field count of the entity type. A null
	 * `$selectedOrmNames` means "every scalar of the type": the index is then walked whole.
	 *
	 * Skips:
	 *
	 *   - columns the type has no scalar field for: `UF_*` names, `PARENT_ID_*` and columns added
	 *     for the permission check all travel in the same select. Relation collections
	 *     (multifields/productRows/bindings) and Utm are not in the index at all - they are loaded
	 *     separately and can never be copied here;
	 *   - fields the source doesn't have ({@see EntityObject::entity}'s schema / legacy field map):
	 *     the registry is filtered by the ORM schema, but a source object can still carry less.
	 *
	 * @param callable(string $ormName): bool  $hasField
	 * @param callable(string $ormName): mixed $getValue
	 * @param string[]|null                    $selectedOrmNames requested ORM columns, or null for "all".
	 */
	private static function copyScalarFields(
		Item $target,
		FieldRegistry $registry,
		callable $hasField,
		callable $getValue,
		?array $selectedOrmNames = null,
	): void
	{
		$scalarFields = $registry->getScalarFieldsByOrmName();

		// "Every scalar" walks the index directly: no key list to allocate and no lookup to do,
		// the descriptor comes with the key.
		if ($selectedOrmNames === null)
		{
			foreach ($scalarFields as $ormName => $descriptor)
			{
				if (!$hasField($ormName))
				{
					continue;
				}

				$value = self::castOrmValueToFieldType($getValue($ormName), $descriptor->type);
				$target->internalSet($descriptor->name, $value);
			}

			return;
		}

		foreach ($selectedOrmNames as $ormName)
		{
			$descriptor = $scalarFields[$ormName] ?? null;
			if ($descriptor === null || !$hasField($ormName))
			{
				continue;
			}

			$value = self::castOrmValueToFieldType($getValue($ormName), $descriptor->type);
			$target->internalSet($descriptor->name, $value);
		}
	}

	/**
	 * ORM-returned values aren't always typed (varchar columns can hold ints, status codes, etc.).
	 * Coerce raw scalar values to the type V2 Item properties declare. Date/Datetime ORM fields
	 * already produce {@see \Bitrix\Main\Type\Date}/{@see \Bitrix\Main\Type\DateTime} objects — pass through.
	 */
	private static function castOrmValueToFieldType(mixed $rawValue, FieldType $type): mixed
	{
		if ($rawValue === null)
		{
			return null;
		}

		return match ($type)
		{
			FieldType::Int => (int)$rawValue,
			FieldType::Float => (float)$rawValue,
			FieldType::Bool => (bool)$rawValue,
			FieldType::String => (string)$rawValue,
			FieldType::File => self::castFileValue($rawValue),
			FieldType::Array => self::convertToArray($rawValue),
			default => $rawValue,
		};
	}

	/**
	 * Pull UF_* values that V2 already knows about back from legacy after Operation.
	 *
	 * Re-reads every UF present in the write Item's bag (not the whole entity - cost control) and
	 * types it by the UF typing matrix (MAP-01) via {@see castCustomFieldValue()}, the same cast the
	 * read path uses. File UFs included: legacy yields raw int ids, wrapped into {@see File}
	 * (id `<= 0` -> `null`; multiple -> `File[]` with non-positive ids dropped). Names legacy hasn't
	 * loaded, or that carry no descriptor, keep their current V2 value.
	 */
	private static function syncCustomFieldsFromLegacy(LegacyItem $legacyItem, Item $v2Item): void
	{
		$customFields = $v2Item->getCustomFields();
		if (empty($customFields))
		{
			return;
		}

		$descriptors = CustomFieldRegistry::getInstance()->getEntityDescriptorsMap($v2Item->getEntityType());

		foreach ($customFields as $ufName => $currentValue)
		{
			$descriptor = $descriptors[$ufName] ?? null;
			if ($descriptor === null || !$legacyItem->hasField($ufName))
			{
				continue;
			}

			$v2Item->internalSet($ufName, self::castCustomFieldValue($legacyItem->get($ufName), $descriptor));
		}
	}

	/**
	 * @param EntityType $entityType
	 * @return array<string, string>
	 */
	private static function getVisibleCustomFields(EntityType $entityType): array
	{
		return CustomFieldRegistry::getInstance()->getVisibleFieldNames($entityType);
	}

	/**
	 * Extract multifield collection of specific type from legacy Item's FM field.
	 */
	private static function extractMultifieldFromLegacy(
		LegacyItem $legacyItem,
		string $fieldName,
		FieldRegistry $registry,
	): mixed
	{
		$typeId = LegacyFieldNameResolver::resolve($fieldName, $registry)[0] ?? null;

		if ($typeId === null)
		{
			return null;
		}

		$fm = $legacyItem->getFm();
		if ($fm === null)
		{
			return null;
		}

		$filtered = $fm->filterByType($typeId);
		if ($filtered->isEmpty())
		{
			return null;
		}

		return match ($fieldName)
		{
			'phones' => MultifieldMapper::toPhoneCollection($filtered),
			'emails' => MultifieldMapper::toEmailCollection($filtered),
			'webs' => MultifieldMapper::toWebCollection($filtered),
			'ims' => MultifieldMapper::toImCollection($filtered),
		};
	}

	/**
	 * Convert legacy value to plain array (e.g. EO_Observer_Collection → int[]).
	 */
	private static function convertToArray(mixed $value, ?string $objectValueField = null): ?array
	{
		if (is_array($value))
		{
			return $value;
		}

		if ($value instanceof \Traversable)
		{
			$result = [];
			foreach ($value as $item)
			{
				if ($objectValueField !== null && is_object($item) && method_exists($item, 'get'))
				{
					$result[] = $item->get($objectValueField);
				}
				elseif (is_object($item) && method_exists($item, 'getId'))
				{
					$result[] = $item->getId();
				}
				else
				{
					$result[] = $item;
				}
			}

			return $result;
		}

		return null;
	}

	/**
	 * Get a field value from V2 Item by FieldDescriptor.
	 */
	private static function getV2FieldValue(Item $v2Item, FieldDescriptor $descriptor): mixed
	{
		$getter = 'get' . ucfirst($descriptor->name);
		if (method_exists($v2Item, $getter))
		{
			return $v2Item->$getter();
		}

		return null;
	}

	/**
	 * Set a field value on legacy Item.
	 */
	private static function setLegacyFieldValue(
		LegacyItem $legacyItem,
		FieldDescriptor $descriptor,
		mixed $value,
		FieldRegistry $registry,
	): void
	{
		if ($descriptor->type === FieldType::ContactBindingCollection)
		{
			self::setLegacyContactBindings($legacyItem, $value);

			return;
		}
		if ($descriptor->type === FieldType::ObserverCollection)
		{
			$legacyItem->set(LegacyItem::FIELD_NAME_OBSERVERS, (array)$value);

			return;
		}

		$converted = match ($descriptor->type)
		{
			FieldType::File => self::convertFileToLegacy($value),
			FieldType::ProductRowCollection => self::convertProductRowsToLegacy($value),
			FieldType::CompanyBindingCollection => self::convertCompanyBindingsToLegacy($value),
			FieldType::MultifieldCollection => null, // handled separately via FM
			default => $value,
		};

		if ($descriptor->type === FieldType::MultifieldCollection)
		{
			self::applyMultifieldToLegacy($legacyItem, $descriptor->name, $value, $registry);

			return;
		}

		if ($descriptor->type === FieldType::Utm)
		{
			self::applyUtmToLegacy($legacyItem, $value);

			return;
		}

		// Legacy set('PRODUCT_ROWS', ...) routes to setProductRows() which requires ProductRow
		// objects; the array shape we build must go through setProductRowsFromArrays() instead.
		if ($descriptor->type === FieldType::ProductRowCollection)
		{
			if (is_array($converted))
			{
				$legacyItem->setProductRowsFromArrays($converted);
			}

			return;
		}

		// Pass null through when the source value is null — that's the caller's explicit "clear" intent
		// (matters on Update via setX(null) for scalars and file fields).
		// Skip only when conversion produced null from a non-null source (e.g. empty collection on Add) —
		// legacy already represents that as "no value", and writing null may trigger unwanted side-effects.
		if ($value === null || $converted !== null)
		{
			$legacyItem->set($descriptor->ormName, $converted);
		}
	}

	private static function setLegacyContactBindings(LegacyItem $legacyItem, mixed $value): void
	{
		if (!$value instanceof ContactBindingCollection)
		{
			return;
		}

		$legacyItem->set(
			LegacyItem::FIELD_NAME_CONTACT_BINDINGS,
			self::convertContactBindingsToLegacy($value),
		);
	}

	private static function copyCustomFieldsToLegacy(Item $v2Item, LegacyItem $legacyItem): void
	{
		$visibleCustomFields = null;
		$customFieldDescriptors = null;
		foreach ($v2Item->getCustomFields() as $name => $value)
		{
			if ($visibleCustomFields === null)
			{
				$visibleCustomFields = static::getVisibleCustomFields($v2Item->getEntityType());
			}
			if (isset($visibleCustomFields[$name]))
			{
				$customFieldDescriptors ??=
					CustomFieldRegistry::getInstance()->getEntityDescriptorsMap($v2Item->getEntityType());
				self::setLegacyCustomField(
					$legacyItem,
					$name,
					$value,
					$customFieldDescriptors[$name] ?? null,
				);
			}
		}
	}

	/**
	 * Apply a UF_* value to legacy, recursively converting V2 File and ItemId values.
	 */
	private static function setLegacyCustomField(
		LegacyItem $legacyItem,
		string $name,
		mixed $value,
		?CustomFieldDescriptor $descriptor,
	): void
	{
		$legacyItem->set($name, self::convertCustomFieldValueToLegacy($value, $descriptor));
	}

	private static function convertCustomFieldValueToLegacy(
		mixed $value,
		?CustomFieldDescriptor $descriptor = null,
	): mixed
	{
		if ($value instanceof File)
		{
			return $value->getId();
		}

		if ($value instanceof ItemId)
		{
			$entityTypesId = $descriptor?->entityTypesId ?? [];
			if (
				$descriptor?->type === 'crm'
				&& count($entityTypesId) === 1
				&& (int)array_values($entityTypesId)[0] === $value->getEntityType()->getId()
			)
			{
				return $value->getId();
			}

			return \CCrmOwnerTypeAbbr::ResolveByTypeID($value->getEntityType()->getId())
				. '_'
				. $value->getId()
			;
		}

		if (is_array($value))
		{
			return array_map(
				static fn(mixed $item): mixed => self::convertCustomFieldValueToLegacy($item, $descriptor),
				$value,
			);
		}

		return $value;
	}

	private static function copyParentIdsToLegacy(Item $v2Item, LegacyItem $legacyItem): void
	{
		foreach ($v2Item->getParentIds() as $parentTypeId => $parentEntityId)
		{
			$legacyItem->set('PARENT_ID_' . $parentTypeId, $parentEntityId);
		}
	}

	private static function convertFileToLegacy(?File $file): ?int
	{
		return $file?->getId();
	}

	/**
	 * Carries every settable field of the row, not just the six this conversion used to know about:
	 * replacing the whole set otherwise wipes the rest of the row. The discount triple
	 * (type + rate + sum) is why - a sum without its type makes price recalculation force the row
	 * onto a percentage discount and zero out both rate and sum.
	 *
	 * A field the row has no value for is left out of the array rather than sent as null: legacy
	 * tells "not provided" from "provided empty". The keys of the original six stay unconditional -
	 * a null TAX_RATE there means "no VAT", not an absent value.
	 */
	private static function convertProductRowsToLegacy(?ProductRowCollection $collection): ?array
	{
		if ($collection === null || $collection->isEmpty())
		{
			return null;
		}

		$rows = [];
		foreach ($collection as $row)
		{
			/** @var ProductRow $row */
			$legacyRow = [
				'PRODUCT_ID' => $row->getProductId(),
				'PRODUCT_NAME' => $row->getName(),
				'QUANTITY' => $row->getQuantity(),
				'PRICE' => $row->getPrice(),
				'DISCOUNT_SUM' => $row->getDiscount(),
				'TAX_RATE' => $row->getTaxRate(),
				'SORT' => $row->getSort(),
			];

			$optional = [
				'ID' => $row->getId(),
				'OWNER_ID' => $row->getOwnerId(),
				'OWNER_TYPE' => self::convertOwnerEntityTypeToLegacy($row->getOwnerEntityType()),
				'DISCOUNT_TYPE_ID' => $row->getDiscountTypeId(),
				'DISCOUNT_RATE' => $row->getDiscountRate(),
				'TAX_NAME' => $row->getTaxName(),
				'TAX_INCLUDED' => self::convertFlagToLegacy($row->getTaxIncluded()),
				'MEASURE_CODE' => $row->getMeasureCode(),
				'MEASURE_NAME' => $row->getMeasureName(),
			];

			foreach ($optional as $legacyName => $value)
			{
				if ($value !== null)
				{
					$legacyRow[$legacyName] = $value;
				}
			}

			$rows[] = $legacyRow;
		}

		return $rows;
	}

	/**
	 * Owner type is an {@see \CCrmOwnerTypeAbbr} short code in storage. An entity type that has no
	 * short code cannot own a row, so it is reported as no owner rather than as an empty code.
	 */
	private static function convertOwnerEntityTypeToLegacy(?EntityType $entityType): ?string
	{
		if ($entityType === null)
		{
			return null;
		}

		$abbreviation = \CCrmOwnerTypeAbbr::ResolveByTypeID($entityType->getId());

		return $abbreviation === \CCrmOwnerTypeAbbr::Undefined ? null : $abbreviation;
	}

	private static function convertFlagToLegacy(?bool $value): ?string
	{
		if ($value === null)
		{
			return null;
		}

		return $value ? 'Y' : 'N';
	}

	private static function convertContactBindingsToLegacy(?ContactBindingCollection $collection): ?array
	{
		if ($collection === null)
		{
			return null;
		}
		if ($collection->isEmpty())
		{
			return [];
		}

		$bindings = [];
		foreach ($collection as $binding)
		{
			/** @var ContactBinding $binding */
			$bindings[] = [
				'CONTACT_ID' => $binding->getContactId(),
				'SORT' => $binding->getSort(),
				'IS_PRIMARY' => $binding->isPrimary() ? 'Y' : 'N',
			];
		}

		return $bindings;
	}

	private static function convertCompanyBindingsToLegacy(?CompanyBindingCollection $collection): ?array
	{
		if ($collection === null)
		{
			return null;
		}
		if ($collection->isEmpty())
		{
			return [];
		}

		$bindings = [];
		foreach ($collection as $binding)
		{
			/** @var CompanyBinding $binding */
			$bindings[] = [
				'COMPANY_ID' => $binding->getCompanyId(),
				'SORT' => $binding->getSort(),
				'IS_PRIMARY' => $binding->isPrimary() ? 'Y' : 'N',
			];
		}

		return $bindings;
	}

	/**
	 * Apply a V2 multifield collection to legacy Item's FM field.
	 * phones/emails/webs/ims all map to the single FM field with different type IDs.
	 */
	private static function applyMultifieldToLegacy(
		LegacyItem $legacyItem,
		string $fieldName,
		?\Bitrix\Main\Entity\EntityCollection $collection,
		FieldRegistry $registry,
	): void
	{
		if ($collection === null)
		{
			return;
		}

		$typeId = LegacyFieldNameResolver::resolve($fieldName, $registry)[0] ?? null;

		if ($typeId === null)
		{
			return;
		}

		// Legacy getFm() hands out a clone, so mutating it in place is lost — we must build the
		// full collection and push it back via setFm().
		$fm = $legacyItem->getFm() ?? new \Bitrix\Crm\Multifield\Collection();

		// Remove existing values of this type
		foreach ($fm->filterByType($typeId) as $existing)
		{
			$fm->remove($existing);
		}

		// Add new values
		foreach ($collection as $multifieldValue)
		{
			/** @var AbstractMultifieldValue $multifieldValue */
			$value = new \Bitrix\Crm\Multifield\Value();
			$value->setTypeId($typeId);
			$value->setValueType($multifieldValue->getValueType());
			$value->setValue($multifieldValue->getValue());

			if ($multifieldValue->getId() !== null)
			{
				$value->setId($multifieldValue->getId());
			}

			if ($multifieldValue instanceof PhoneValue && $multifieldValue->getCountryCode() !== null)
			{
				$value->setValueExtra(
					(new \Bitrix\Crm\Multifield\ValueExtra())
						->setCountryCode($multifieldValue->getCountryCode())
				);
			}

			$fm->add($value);
		}

		$legacyItem->setFm($fm);
	}

	/**
	 * Apply a V2 Utm Entity to legacy Item's UTM_* fields.
	 */
	private static function applyUtmToLegacy(LegacyItem $legacyItem, ?Utm $utm): void
	{
		$values = [];
		// assign false to legacy Item's UTM_* field to delete it
		if ($utm === null)
		{
			$values = array_fill_keys(UtmTable::getCodeList(), false);
		}
		else
		{
			if ($utm->isChanged('source'))
			{
				$values[UtmTable::ENUM_CODE_UTM_SOURCE] = $utm->getSource() ?? false;
			}
			if ($utm->isChanged('medium'))
			{
				$values[UtmTable::ENUM_CODE_UTM_MEDIUM] = $utm->getMedium() ?? false;
			}
			if ($utm->isChanged('campaign'))
			{
				$values[UtmTable::ENUM_CODE_UTM_CAMPAIGN] = $utm->getCampaign() ?? false;
			}
			if ($utm->isChanged('content'))
			{
				$values[UtmTable::ENUM_CODE_UTM_CONTENT] = $utm->getContent() ?? false;
			}
			if ($utm->isChanged('term'))
			{
				$values[UtmTable::ENUM_CODE_UTM_TERM] = $utm->getTerm() ?? false;
			}
		}

		foreach ($values as $code => $value)
		{
			$legacyItem->set($code, $value);
		}
	}
}
