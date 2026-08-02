<?php

declare(strict_types=1);

namespace Bitrix\Tasks\Integration\BizProc\NodeFilter;

trait ExposesFilterResultsToProperties
{
	private const FILTER_RETURN_PROPERTIES_MAP_PROPERTY = 'FilterReturnPropertiesMap';
	private const FILTER_RESULT_ALL_SUFFIX = '_all';

	protected function initializeNodeFilterResultProperties(): void
	{
		$returnPropertiesMap = $this->getRawProperty(self::FILTER_RETURN_PROPERTIES_MAP_PROPERTY);
		if (!is_array($returnPropertiesMap))
		{
			return;
		}

		foreach (array_keys($returnPropertiesMap) as $propertyId)
		{
			$this->arProperties[$propertyId] = null;
		}

		if (empty($returnPropertiesMap))
		{
			return;
		}

		$propertyTypes = [];
		foreach ($returnPropertiesMap as $propertyId => $property)
		{
			if (is_array($property))
			{
				$propertyTypes[$propertyId] = $property;
			}
		}

		if (!empty($propertyTypes))
		{
			$this->setPropertiesTypes($propertyTypes);
		}

		foreach (Resolver::resolveDocuments($this) as $filterId => $result)
		{
			if (array_key_exists($filterId, $returnPropertiesMap))
			{
				$this->arProperties[$filterId] = $result['documentId'] ?? null;
			}

			$allPropertyId = $filterId . self::FILTER_RESULT_ALL_SUFFIX;
			if (array_key_exists($allPropertyId, $returnPropertiesMap))
			{
				$this->arProperties[$allPropertyId] = $result['documentIds'] ?? [];
			}
		}
	}
}
