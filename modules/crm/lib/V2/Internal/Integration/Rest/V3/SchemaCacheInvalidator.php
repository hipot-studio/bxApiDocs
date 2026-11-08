<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\Rest\V3;

/**
 * Keeps the cached REST schema in step with the composition of the entity types of the portal.
 *
 * The schema is built whole and cached whole, so without this a smart process would wait out the
 * cache before its methods answered, and a deleted one would leave dead routes behind. The events of
 * the type table are the single place every such change goes through, so that is where the calls
 * come from.
 *
 * Capability flags shape the routes and fields of the schema: {@see \Bitrix\Crm\V2\Public\EntityTypeSettings}
 * supplies the settings, and both the routes of an entity type
 * ({@see \Bitrix\Crm\V2\Infrastructure\Rest\CustomController\Category\RouteBuilder}) and the fields of
 * its DTO ({@see \Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\ItemDtoGenerator}) are derived from those
 * settings. Type titles also appear in the cached descriptions, so renaming a type invalidates the
 * schema. Other changes to its row do not need to pay for that `cleanDir` over the shared REST cache.
 *
 * Only the schema is dropped here. The composition of the types themselves lives in another cache
 * with its own invalidation, which the type events do on their own before calling in.
 *
 * @internal
 */
class SchemaCacheInvalidator
{
	/**
	 * The fields of a type row the REST schema is built from - every capability
	 * {@see \Bitrix\Crm\V2\Public\EntityTypeSettings} reads off it. `IS_SET_OPEN_PERMISSIONS` (the
	 * permissions of items, not a capability of the type) and `IS_INITIALIZED` (the lifecycle of the
	 * type row itself) are deliberately left out.
	 */
	private const SCHEMA_AFFECTING_TYPE_FIELDS = [
		'IS_CATEGORIES_ENABLED',
		'IS_STAGES_ENABLED',
		'IS_BEGIN_CLOSE_DATES_ENABLED',
		'IS_CLIENT_ENABLED',
		'IS_USE_IN_USERFIELD_ENABLED',
		'IS_LINK_WITH_PRODUCTS_ENABLED',
		'IS_CRM_TRACKING_ENABLED',
		'IS_MYCOMPANY_ENABLED',
		'IS_DOCUMENTS_ENABLED',
		'IS_SOURCE_ENABLED',
		'IS_OBSERVERS_ENABLED',
		'IS_RECURRING_ENABLED',
		'IS_RECYCLEBIN_ENABLED',
		'IS_AUTOMATION_ENABLED',
		'IS_BIZ_PROC_ENABLED',
		'IS_PAYMENTS_ENABLED',
		'IS_COUNTERS_ENABLED',
	];

	public static function invalidate(): void
	{
		CacheManager::cleanAll();
	}

	/**
	 * @param array $newFields the fields the type row has been saved with
	 * @param array $oldFields the row as it was before the save
	 */
	public static function invalidateIfSchemaAffected(array $newFields, array $oldFields): void
	{
		if (static::isSchemaAffected($newFields, $oldFields))
		{
			static::invalidate();
		}
	}

	protected static function isSchemaAffected(array $newFields, array $oldFields): bool
	{
		foreach (self::SCHEMA_AFFECTING_TYPE_FIELDS as $fieldName)
		{
			if (!array_key_exists($fieldName, $newFields))
			{
				continue;
			}

			if (
				!array_key_exists($fieldName, $oldFields)
				|| static::toBool($newFields[$fieldName]) !== static::toBool($oldFields[$fieldName])
			)
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * A boolean field of a type row reaches an event either as a PHP boolean or in the `Y`/`N` form it
	 * is stored in, depending on how the row was saved.
	 */
	protected static function toBool(mixed $value): bool
	{
		return is_bool($value) ? $value : $value === 'Y';
	}
}
