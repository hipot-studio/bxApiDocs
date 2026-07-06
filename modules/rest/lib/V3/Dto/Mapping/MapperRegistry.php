<?php

namespace Bitrix\Rest\V3\Dto\Mapping;

use Bitrix\Rest\V3\Attribute\MappedBy;
use Bitrix\Rest\V3\Attribute\OrmEntity;

final class MapperRegistry
{
	/**
	 * Maps DTO class name → mapper class name (or false when MappedBy is not applicable).
	 * Populated on first access via reflection; subsequent calls are O(1) array lookups.
	 */
	private static array $dtoToMapperClass = [];

	/**
	 * Maps mapper class name → single shared Mapper instance.
	 * Guarantees one instance per mapper class across the entire request lifecycle.
	 */
	private static array $mapperInstances = [];

	/**
	 * Returns the shared Mapper instance for the given DTO class, or null when:
	 *   - the DTO has no #[MappedBy] attribute, or
	 *   - the DTO has #[OrmEntity] (ORM path takes priority over MappedBy).
	 *
	 * Uses reflection only once per DTO class; subsequent calls use the static cache.
	 */
	public static function getForDto(string $dtoClass): ?Mapper
	{
		if (!array_key_exists($dtoClass, self::$dtoToMapperClass))
		{
			self::$dtoToMapperClass[$dtoClass] = self::resolveMapperClass($dtoClass);
		}

		$mapperClass = self::$dtoToMapperClass[$dtoClass];
		if ($mapperClass === false)
		{
			return null;
		}

		if (!isset(self::$mapperInstances[$mapperClass]))
		{
			self::$mapperInstances[$mapperClass] = new $mapperClass();
		}

		return self::$mapperInstances[$mapperClass];
	}

	/**
	 * Resolves the mapper class name for the given DTO class via reflection.
	 * Returns false when MappedBy is not applicable (no attribute or OrmEntity present).
	 */
	private static function resolveMapperClass(string $dtoClass): string|false
	{
		$reflection = new \ReflectionClass($dtoClass);

		// OrmEntity takes priority — MappedBy is disabled for ORM-backed DTOs
		if (!empty($reflection->getAttributes(OrmEntity::class)))
		{
			return false;
		}

		$attributes = $reflection->getAttributes(MappedBy::class);
		if (empty($attributes))
		{
			return false;
		}

		/** @var MappedBy $mappedBy */
		$mappedBy = $attributes[0]->newInstance();

		return $mappedBy->mapperClass;
	}

	/**
	 * Resets all cached entries. Use in tests to isolate state between test cases.
	 */
	public static function reset(): void
	{
		self::$dtoToMapperClass = [];
		self::$mapperInstances = [];
	}
}
