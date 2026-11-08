<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Activity\Mixins;

use Bitrix\Bizproc\FieldType;
use CBPActivity;

trait RelationPackageProperties
{
	public const RELATION_RETURN_PROPERTIES_MAP = 'RelationReturnPropertiesMap';

	private const SOURCE_ACTIVITY = 'SourceActivity';
	private const SOURCE_PROPERTY = 'SourceProperty';

	/** @var \WeakMap<CBPActivity, array<string, array<string, string>>>|null */
	private static ?\WeakMap $relationReturnPropertiesIndex = null;

	public static function getRelationServiceProperties(): array
	{
		return [
			self::RELATION_RETURN_PROPERTIES_MAP => [
				'Name' => self::RELATION_RETURN_PROPERTIES_MAP,
				'Type' => FieldType::JSON,
				'Hidden' => true,
			],
		];
	}

	protected static function getRelationPropertyDefaults(): array
	{
		return array_fill_keys(
			array_keys(static::getRelationServiceProperties()),
			null,
		);
	}

	protected static function getRelationPropertyTypes(): array
	{
		return array_map(
			static fn(array $property): array => ['Type' => $property['Type']],
			static::getRelationServiceProperties(),
		);
	}

	protected function initializeRelationPackageProperties(): void
	{
		self::initializeRelationPackagePropertiesOf($this);
	}

	public static function initializeRelationPackagePropertiesOf(CBPActivity $activity): void
	{
		$propertiesMap = self::getRelationReturnPropertiesMapOf($activity);
		self::getRelationReturnPropertiesIndex()[$activity] = self::buildRelationReturnPropertiesIndex($propertiesMap);
		if (empty($propertiesMap))
		{
			return;
		}

		$activity->setProperties(array_fill_keys(array_keys($propertiesMap), null));

		$propertyTypes = array_filter($propertiesMap, 'is_array');
		if ($propertyTypes !== [])
		{
			$activity->setPropertiesTypes($propertyTypes);
		}
	}

	protected function transferRelationPackageProperties(CBPActivity $sourceActivity): void
	{
		self::transferRelationPackagePropertiesOf($this, $sourceActivity);
	}

	public static function transferRelationPackagePropertiesOf(CBPActivity $activity, CBPActivity $sourceActivity): void
	{
		$index = self::getRelationReturnPropertiesIndex();
		if (!isset($index[$activity]))
		{
			$index[$activity] = self::buildRelationReturnPropertiesIndex(
				self::getRelationReturnPropertiesMapOf($activity),
			);
		}

		$propertiesMap = $index[$activity][$sourceActivity->getName()] ?? [];
		if ($propertiesMap === [])
		{
			return;
		}

		$values = [];
		foreach ($propertiesMap as $propertyId => $sourceProperty)
		{
			$values[(string)$propertyId] = $sourceActivity->getRawPropertyValue($sourceProperty);
		}

		if (!empty($values))
		{
			$activity->setProperties($values);
		}
	}

	private static function getRelationReturnPropertiesMapOf(CBPActivity $activity): array
	{
		$propertiesMap = $activity->getRawPropertyValue(self::RELATION_RETURN_PROPERTIES_MAP);

		return is_array($propertiesMap) ? $propertiesMap : [];
	}

	/**
	 * @param array<string, mixed> $propertiesMap
	 * @return array<string, array<string, string>>
	 */
	private static function buildRelationReturnPropertiesIndex(array $propertiesMap): array
	{
		$index = [];
		foreach ($propertiesMap as $propertyId => $property)
		{
			if (!is_array($property))
			{
				continue;
			}

			$sourceActivity = $property[self::SOURCE_ACTIVITY] ?? null;
			$sourceProperty = $property[self::SOURCE_PROPERTY] ?? null;
			if (
				!is_string($sourceActivity)
				|| $sourceActivity === ''
				|| !is_string($sourceProperty)
				|| $sourceProperty === ''
			)
			{
				continue;
			}

			$index[$sourceActivity][(string)$propertyId] = $sourceProperty;
		}

		return $index;
	}

	/** @return \WeakMap<CBPActivity, array<string, array<string, string>>> */
	private static function getRelationReturnPropertiesIndex(): \WeakMap
	{
		self::$relationReturnPropertiesIndex ??= new \WeakMap();

		return self::$relationReturnPropertiesIndex;
	}
}
