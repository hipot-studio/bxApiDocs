<?php

namespace Bitrix\Mail\Internals;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\Data\Cache;
use Bitrix\Main\Entity;
use Bitrix\Main\ORM;
use Bitrix\Main\Type\DateTime;

/**
 * Class MailEntityOptionsTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_MailEntityOptions_Query query()
 * @method static EO_MailEntityOptions_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_MailEntityOptions_Result getById($id)
 * @method static EO_MailEntityOptions_Result getList(array $parameters = [])
 * @method static EO_MailEntityOptions_Entity getEntity()
 * @method static \Bitrix\Mail\Internals\EO_MailEntityOptions createObject($setDefaultValues = true)
 * @method static \Bitrix\Mail\Internals\EO_MailEntityOptions_Collection createCollection()
 * @method static \Bitrix\Mail\Internals\EO_MailEntityOptions wakeUpObject($row)
 * @method static \Bitrix\Mail\Internals\EO_MailEntityOptions_Collection wakeUpCollection($rows)
 */
class MailEntityOptionsTable extends Entity\DataManager
{
	const DIR_TYPE_NAME = 'DIR';
	const MAILBOX_TYPE_NAME = 'MAILBOX';
	const MESSAGE_TYPE_NAME = 'MESSAGE';
	const USER_TYPE_NAME = 'USER';

	const CONNECT_ERROR_ATTEMPT_COUNT_PROPERTY_NAME = 'CONNECT_ERROR_ATTEMPT_COUNT';
	const PROBLEM_STATUS_PROPERTY_NAME = 'PROBLEM_STATUS';
	const SYNC_STATUS_PROPERTY_NAME = 'SYNC_STATUS';

	private const MAILBOX_OPTION_CACHE_TTL = 86400;
	private const MAILBOX_OPTION_CACHE_KEY_PREFIX = 'mail_mailbox_option_';
	private const MISSING_VALUE_MARKER = false;

	// Properties tracked by per-property cache. Add here to enable caching + auto-invalidation.
	private const CACHEABLE_PROPERTIES = [
		self::SYNC_STATUS_PROPERTY_NAME,
		self::PROBLEM_STATUS_PROPERTY_NAME,
	];

	public static function add($fields)
	{
		try
		{
			$result = parent::add($fields);
		}
		catch (\Exception $exception)
		{
			return null;
		}

		if (self::shouldInvalidatePropertyCache($fields['ENTITY_TYPE'] ?? null, $fields['PROPERTY_NAME'] ?? null))
		{
			self::invalidateMailboxOptionCache(
				(int)($fields['MAILBOX_ID'] ?? 0),
				$fields['PROPERTY_NAME']
			);
		}

		return $result;
	}

	public static function update($primary, array $data)
	{
		$result = parent::update($primary, $data);

		if (
			is_array($primary)
			&& self::shouldInvalidatePropertyCache($primary['ENTITY_TYPE'] ?? null, $primary['PROPERTY_NAME'] ?? null)
		)
		{
			self::invalidateMailboxOptionCache(
				(int)($primary['MAILBOX_ID'] ?? 0),
				$primary['PROPERTY_NAME']
			);
		}

		return $result;
	}

	public static function delete($primary)
	{
		$result = parent::delete($primary);

		if (
			is_array($primary)
			&& self::shouldInvalidatePropertyCache($primary['ENTITY_TYPE'] ?? null, $primary['PROPERTY_NAME'] ?? null)
		)
		{
			self::invalidateMailboxOptionCache(
				(int)($primary['MAILBOX_ID'] ?? 0),
				$primary['PROPERTY_NAME']
			);
		}

		return $result;
	}

	public static function deleteList(array $filter): \Bitrix\Main\DB\Result
	{
		$entity = static::getEntity();
		$connection = $entity->getConnection();

		$result = $connection->query(sprintf(
			'DELETE FROM %s WHERE %s',
			$connection->getSqlHelper()->quote($entity->getDbTableName()),
			ORM\Query\Query::buildFilterSql($entity, $filter)
		));

		$mailboxId = (int)($filter['=MAILBOX_ID'] ?? 0);
		$entityType = $filter['=ENTITY_TYPE'] ?? null;

		if ($mailboxId > 0 && ($entityType === null || $entityType === self::MAILBOX_TYPE_NAME))
		{
			$propertiesFromFilter = self::extractPropertyNamesFromFilter($filter);
			$propertiesToInvalidate = !empty($propertiesFromFilter)
				? $propertiesFromFilter
				: self::CACHEABLE_PROPERTIES;

			foreach ($propertiesToInvalidate as $propertyName)
			{
				if (self::isCacheableProperty($propertyName))
				{
					self::invalidateMailboxOptionCache($mailboxId, $propertyName);
				}
			}
		}

		return $result;
	}

	public static function getFilePath()
	{
		return __FILE__;
	}

	public static function getTableName()
	{
		return 'b_mail_entity_options';
	}

	public static function insertIgnore(
		int $mailboxId,
		int $entityId,
		string $entityType,
		string $propertyName,
		string $value,
		DateTime $dataInsert = new DateTime()
	): void
	{
		$connection = self::getEntity()->getConnection();
		$sqlHelper = $connection->getSqlHelper();

		[$columns, $insert] = $sqlHelper->prepareInsert(self::getTableName(),
			[
				'MAILBOX_ID' => $mailboxId,
				'ENTITY_TYPE' => $entityType,
				'ENTITY_ID' => $entityId,
				'PROPERTY_NAME' => $propertyName,
				'DATE_INSERT' => $dataInsert,
				'VALUE' => $value,
			]
		);

		$connection->queryExecute(
			$sqlHelper->getInsertIgnore(
				MailEntityOptionsTable::getTableName(),
				"($columns)",
				"VALUES($insert)"
			)
		);

		if (self::shouldInvalidatePropertyCache($entityType, $propertyName))
		{
			self::invalidateMailboxOptionCache($mailboxId, $propertyName);
		}
	}

	/**
	 * Convenience wrapper around getCachedMailboxesOptions for the single-mailbox case.
	 * Requested property names must all be listed in self::CACHEABLE_PROPERTIES;
	 * an unknown name throws ArgumentException to catch typos at the call site.
	 */
	public static function getCachedMailboxOptions(int $mailboxId, array $propertyNames): array
	{
		return self::getCachedMailboxesOptions([$mailboxId], $propertyNames)[$mailboxId] ?? [];
	}

	public static function getCachedMailboxesOptions(array $mailboxIds, array $propertyNames): array
	{
		if (empty($mailboxIds) || empty($propertyNames))
		{
			return [];
		}

		foreach ($propertyNames as $propertyName)
		{
			if (!self::isCacheableProperty($propertyName))
			{
				throw new ArgumentException(
					sprintf('Property "%s" is not declared in CACHEABLE_PROPERTIES', $propertyName),
					'propertyNames'
				);
			}
		}

		$result = [];
		$missMap = [];

		foreach ($mailboxIds as $rawMailboxId)
		{
			$mailboxId = (int)$rawMailboxId;
			$result[$mailboxId] = [];

			foreach ($propertyNames as $propertyName)
			{
				$cached = self::tryReadPropertyCache($mailboxId, $propertyName);

				if ($cached === null)
				{
					$missMap[$mailboxId][] = $propertyName;
				}
				elseif ($cached !== self::MISSING_VALUE_MARKER)
				{
					$result[$mailboxId][$propertyName] = $cached;
				}
			}
		}

		if (empty($missMap))
		{
			return $result;
		}

		$missedMailboxIds = array_keys($missMap);
		$missedProperties = array_values(array_unique(array_merge(...array_values($missMap))));

		$rows = self::getList([
			'select' => ['MAILBOX_ID', 'PROPERTY_NAME', 'VALUE', 'DATE_INSERT'],
			'filter' => [
				'=MAILBOX_ID' => $missedMailboxIds,
				'=ENTITY_ID' => array_map('strval', $missedMailboxIds),
				'=ENTITY_TYPE' => self::MAILBOX_TYPE_NAME,
				'=PROPERTY_NAME' => $missedProperties,
			],
		])->fetchAll();

		$fetched = [];
		foreach ($rows as $row)
		{
			$mid = (int)$row['MAILBOX_ID'];
			$fetched[$mid][$row['PROPERTY_NAME']] = [
				'VALUE' => $row['VALUE'],
				'DATE_INSERT' => $row['DATE_INSERT'],
			];
		}

		foreach ($missMap as $mailboxId => $missedForMailbox)
		{
			foreach ($missedForMailbox as $propertyName)
			{
				$data = $fetched[$mailboxId][$propertyName] ?? self::MISSING_VALUE_MARKER;

				self::writePropertyCache($mailboxId, $propertyName, $data);

				if ($data !== self::MISSING_VALUE_MARKER)
				{
					$result[$mailboxId][$propertyName] = $data;
				}
			}
		}

		return $result;
	}

	public static function invalidateMailboxOptionCache(int $mailboxId, string $propertyName): void
	{
		Cache::createInstance()->clean(
			self::buildPropertyCacheKey($mailboxId, $propertyName),
			self::getCacheDirForProperty($mailboxId, $propertyName),
		);
	}

	public static function getMap()
	{
		return array(
			'MAILBOX_ID' => array(
				'data_type' => 'integer',
				'required'  => true,
				'primary' => true,
			),
			'ENTITY_TYPE' => array(
				'data_type' => 'enum',
				'values' => array(self::DIR_TYPE_NAME, self::MAILBOX_TYPE_NAME, self::MESSAGE_TYPE_NAME, self::USER_TYPE_NAME),
				'required'  => true,
				'primary' => true,
			),
			'ENTITY_ID' => array(
				'data_type' => 'string',
				'required'  => true,
				'primary' => true,
			),
			'PROPERTY_NAME' => array(
				'data_type' => 'string',
				'required'  => true,
				'primary' => true,
			),
			'VALUE' => array(
				'data_type' => 'string',
			),
			'DATE_INSERT' => array(
				'data_type' => 'datetime',
			),
		);
	}

	private static function tryReadPropertyCache(int $mailboxId, string $propertyName): mixed
	{
		$cache = Cache::createInstance();
		$cacheKey = self::buildPropertyCacheKey($mailboxId, $propertyName);
		$cacheDir = self::getCacheDirForProperty($mailboxId, $propertyName);

		if ($cache->initCache(self::MAILBOX_OPTION_CACHE_TTL, $cacheKey, $cacheDir))
		{
			$vars = $cache->getVars();

			return is_array($vars) && array_key_exists('data', $vars)
				? $vars['data']
				: null;
		}

		return null;
	}

	private static function writePropertyCache(int $mailboxId, string $propertyName, mixed $data): void
	{
		$cache = Cache::createInstance();
		$cacheKey = self::buildPropertyCacheKey($mailboxId, $propertyName);
		$cacheDir = self::getCacheDirForProperty($mailboxId, $propertyName);

		if ($cache->startDataCache(self::MAILBOX_OPTION_CACHE_TTL, $cacheKey, $cacheDir))
		{
			$cache->endDataCache(['data' => $data]);
		}
	}

	private static function buildPropertyCacheKey(int $mailboxId, string $propertyName): string
	{
		return self::MAILBOX_OPTION_CACHE_KEY_PREFIX . $mailboxId . '_' . $propertyName;
	}

	private static function getCacheDirForProperty(int $mailboxId, string $propertyName): string
	{
		$cacheKey = self::buildPropertyCacheKey($mailboxId, $propertyName);

		return '/mail/entity_options/' . substr(md5($cacheKey), 2, 2) . '/' . $cacheKey . '/';
	}

	private static function isCacheableProperty(mixed $propertyName): bool
	{
		return in_array($propertyName, self::CACHEABLE_PROPERTIES, true);
	}

	private static function shouldInvalidatePropertyCache(mixed $entityType, mixed $propertyName): bool
	{
		return $entityType === self::MAILBOX_TYPE_NAME && self::isCacheableProperty($propertyName);
	}

	private static function extractPropertyNamesFromFilter(array $filter): array
	{
		$value = $filter['=PROPERTY_NAME'] ?? null;

		if (is_string($value))
		{
			return [$value];
		}

		if (is_array($value))
		{
			return array_values(array_filter($value, 'is_string'));
		}

		return [];
	}
}
