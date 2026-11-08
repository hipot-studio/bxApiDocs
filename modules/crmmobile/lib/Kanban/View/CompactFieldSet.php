<?php

declare(strict_types=1);

namespace Bitrix\CrmMobile\Kanban\View;

use Bitrix\Crm\Item;
use Bitrix\Crm\Item\Quote;

/**
 * Fields shown in the compact item view. The set depends on the entity type only, never on a category.
 */
final class CompactFieldSet
{
	private const FIELDS_BY_ENTITY_TYPE = [
		\CCrmOwnerType::Deal => [],
		\CCrmOwnerType::Lead => [],
		\CCrmOwnerType::Quote => [Quote::FIELD_NAME_ACTUAL_DATE],
		\CCrmOwnerType::SmartInvoice => [Item::FIELD_NAME_CLOSE_DATE],
		\CCrmOwnerType::Contact => [Item::FIELD_NAME_ASSIGNED],
		\CCrmOwnerType::Company => [Item::FIELD_NAME_ASSIGNED],
	];

	private const DYNAMIC_TYPE_FIELDS = [
		Item::FIELD_NAME_ASSIGNED,
		Item::FIELD_NAME_CREATED_TIME,
	];

	/**
	 * @return string[] an empty set is a legal result: such an entity shows no fields at all
	 */
	public static function forEntity(int $entityTypeId): array
	{
		if (array_key_exists($entityTypeId, self::FIELDS_BY_ENTITY_TYPE))
		{
			return self::FIELDS_BY_ENTITY_TYPE[$entityTypeId];
		}

		if (\CCrmOwnerType::isPossibleDynamicTypeId($entityTypeId))
		{
			return self::DYNAMIC_TYPE_FIELDS;
		}

		return [];
	}
}
