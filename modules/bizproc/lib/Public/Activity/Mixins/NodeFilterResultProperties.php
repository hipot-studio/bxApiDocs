<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Activity\Mixins;

use Bitrix\Bizproc\FieldType;
use Bitrix\Bizproc\Internal\Service\Container;

/**
 * The mechanics of the node filter: the two properties the filter block saves into a node, and the publication
 * of the selected documents into the properties of that node before it does its work.
 *
 * The publication is offered as a static too ({@see self::initializeFilterResultPropertiesOf()}), for a node
 * that carries the filter properties by completion and does not compose the mixin. A composing class publishes
 * at its own point and must not be published a second time - the resolver would query the selection twice.
 */
trait NodeFilterResultProperties
{
	public const FILTER_SETTINGS = 'FilterSettings';
	public const FILTER_RETURN_PROPERTIES_MAP = 'FilterReturnPropertiesMap';

	/** Suffix of the paired collection result: `<filterId>` is the first document, `<filterId>_all` all of them. */
	public const FILTER_RESULT_ALL_SUFFIX = '_all';

	/**
	 * The single declaration of the filter properties, in the shape a properties map carries. Both are marked
	 * hidden because neither is a control of the settings form: the filter is edited by a block of its own,
	 * and {@see \Bitrix\Bizproc\Public\Activity\ActivityControlsBuilder} skips `FieldType::RULES` but not JSON.
	 *
	 * @return array<string, array>
	 */
	public static function getFilterServiceProperties(): array
	{
		return [
			self::FILTER_SETTINGS => [
				'Name' => self::FILTER_SETTINGS,
				'FieldName' => self::FILTER_SETTINGS,
				'Type' => FieldType::RULES,
				'Default' => [],
				'Hidden' => true,
			],
			self::FILTER_RETURN_PROPERTIES_MAP => [
				'Name' => self::FILTER_RETURN_PROPERTIES_MAP,
				'FieldName' => self::FILTER_RETURN_PROPERTIES_MAP,
				'Type' => FieldType::JSON,
				'Default' => [],
				'Hidden' => true,
			],
		];
	}

	/** @return array<string, mixed> property name => default value, as an activity constructor declares them */
	protected static function getFilterPropertyDefaults(): array
	{
		return array_map(
			static fn(array $property) => $property['Default'],
			static::getFilterServiceProperties(),
		);
	}

	/** @return array<string, array> property name => type descriptor, as an activity constructor declares them */
	protected static function getFilterPropertyTypes(): array
	{
		return array_map(
			static fn(array $property) => ['Type' => $property['Type']],
			static::getFilterServiceProperties(),
		);
	}

	protected function initializeFilterResultProperties(): void
	{
		self::initializeFilterResultPropertiesOf($this);
	}

	/**
	 * Declares the filter result properties of the node and fills them with what its filter selected. They are
	 * declared even when no resolver answers, so a reference never resolves to a value of a previous run.
	 */
	public static function initializeFilterResultPropertiesOf(\CBPActivity $activity): void
	{
		$returnPropertiesMap = $activity->getRawPropertyValue(self::FILTER_RETURN_PROPERTIES_MAP);
		if (!is_array($returnPropertiesMap) || $returnPropertiesMap === [])
		{
			return;
		}

		$activity->setProperties(array_fill_keys(array_keys($returnPropertiesMap), null));

		$propertyTypes = array_filter($returnPropertiesMap, 'is_array');
		if ($propertyTypes !== [])
		{
			$activity->setPropertiesTypes($propertyTypes);
		}

		$resolver = Container::instance()->getFilterResultPropertyResolverRegistry()->resolve($activity);
		if ($resolver === null)
		{
			return;
		}

		$results = [];
		foreach ($resolver->resolveProperties($activity) as $filterId => $result)
		{
			if (array_key_exists($filterId, $returnPropertiesMap))
			{
				$results[$filterId] = $result['documentId'] ?? null;
			}

			$allPropertyId = $filterId . self::FILTER_RESULT_ALL_SUFFIX;
			if (array_key_exists($allPropertyId, $returnPropertiesMap))
			{
				$results[$allPropertyId] = $result['documentIds'] ?? [];
			}

			foreach (self::getAdditionalResults($result) as $key => $documentId)
			{
				$additionalPropertyId = $filterId . '_' . $key;
				if (array_key_exists($additionalPropertyId, $returnPropertiesMap))
				{
					$results[$additionalPropertyId] = $documentId;
				}
			}
		}

		$activity->setProperties($results);
	}

	/**
	 * Documents of another module the filter publishes beside the ones it found, declared by
	 * {@see \Bitrix\Bizproc\Public\Activity\Interface\NodeFilterAdditionalResultProvider}.
	 *
	 * @return array<string, mixed>
	 */
	private static function getAdditionalResults(array $filterResult): array
	{
		return is_array($filterResult['additional'] ?? null) ? $filterResult['additional'] : [];
	}
}
