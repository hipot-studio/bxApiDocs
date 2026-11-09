<?php

namespace Bitrix\BIConnector\Internal\Operation\Builder\Layout;

use Bitrix\Main\Web\Json;

final class LayoutWriteEncoder
{
	private const OBJECT_KEYS = [
		'chart_configuration',
		'global_chart_configuration',
		'filter_scopes',
		'expanded_slices',
		'label_colors',
		'map_label_colors',
		'native_filter_migration',
	];

	public function encode(array $jsonMetadata, array $positions): string
	{
		$jsonMetadata = $this->restoreEmptyObjects($jsonMetadata, self::OBJECT_KEYS);
		if (is_array($jsonMetadata['native_filter_configuration'] ?? null))
		{
			foreach ($jsonMetadata['native_filter_configuration'] as $key => $filter)
			{
				if (is_array($filter))
				{
					$jsonMetadata['native_filter_configuration'][$key] = $this->normalizeFilter($filter);
				}
			}
		}

		foreach ($positions as $id => $component)
		{
			if (is_array($component))
			{
				$positions[$id] = $this->restoreEmptyObjects($component, ['meta']);
			}
		}
		$jsonMetadata['positions'] = $positions;

		return Json::encode($jsonMetadata);
	}

	private function normalizeFilter(array $filter): array
	{
		$filter = $this->restoreEmptyObjects($filter, ['controlValues']);
		if (is_array($filter['targets'] ?? null))
		{
			foreach ($filter['targets'] as $key => $target)
			{
				if ($target === [])
				{
					$filter['targets'][$key] = new \stdClass();
				}
			}
		}

		if (is_array($filter['defaultDataMask'] ?? null))
		{
			$filter['defaultDataMask'] = $this->restoreEmptyObjects(
				$filter['defaultDataMask'],
				['extraFormData', 'filterState', 'ownState'],
			);
		}

		return $filter;
	}

	private function restoreEmptyObjects(array $data, array $keys): array
	{
		foreach ($keys as $key)
		{
			if (($data[$key] ?? null) === [])
			{
				$data[$key] = new \stdClass();
			}
		}

		return $data;
	}
}
