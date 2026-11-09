<?php

namespace Bitrix\BIConnector\Internal\Operation\Builder\Argument;

final class BuilderArgumentNormalizer
{
	public static function normalize(string $uri, array $validated): array
	{
		$result = self::defaults(BuilderArgumentContract::forMethod($uri), $validated);
		$method = substr($uri, strlen('biconnector.builder.'));
		$trimmed = match ($method) {
			'dashboard.add', 'dashboard.update', 'chart.add', 'chart.update' => ['name'],
			'dataset.add' => ['datasetName', 'sql'],
			'dataset.addfromtable' => ['datasetName', 'sourceTable'],
			'table.schema.get' => ['tableName'],
			default => [],
		};
		foreach ($trimmed as $field)
		{
			if (isset($result[$field]))
			{
				$result[$field] = trim($result[$field]);
			}
		}
		if ($method === 'dashboard.parameters.search')
		{
			$result['offset'] = max(0, $result['offset']);
		}
		if (in_array($method, ['chart.list', 'dataset.list'], true))
		{
			$result['offset'] = intdiv($result['offset'], $result['limit']) * $result['limit'];
		}
		if ($method === 'chart.data.get')
		{
			$result['chartIds'] = array_values(array_unique($result['chartIds']));
		}
		if ($method === 'dashboard.content.set')
		{
			foreach ($result['charts'] ?? [] as $index => $chart)
			{
				$result['charts'][$index]['sliceName'] = trim($chart['sliceName']);
			}
			foreach ($result['filters'] ?? [] as $index => $filter)
			{
				$filter['name'] = trim($filter['name']);
				if (isset($filter['column']))
				{
					$filter['column'] = trim($filter['column']);
				}
				if (isset($filter['chartIds']))
				{
					$filter['chartIds'] = array_values(array_unique($filter['chartIds']));
				}
				if ($filter['filterType'] === 'time_range' && isset($filter['defaultValue']))
				{
					$filter['defaultValue'] = trim($filter['defaultValue']);
				}
				$result['filters'][$index] = $filter;
			}
		}
		if (in_array($method, ['chart.add', 'chart.config.set'], true))
		{
			$result = self::chart($result);
		}
		if ($method === 'dataset.addfromtable')
		{
			$result['columns'] = self::strings($result['columns']);
		}
		if ($method === 'dataset.update')
		{
			foreach ($result['columns'] as $index => $column)
			{
				$result['columns'][$index]['name'] = trim($column['name']);
			}
		}

		return $result;
	}

	private static function chart(array $arguments): array
	{
		$arguments['temporalColumn'] = trim($arguments['temporalColumn']);
		$arguments['dimensions'] = array_values(array_unique(self::strings($arguments['dimensions'])));
		$viz = $arguments['vizType'];
		$defaultLimit = match ($viz) {
			'pie' => 100,
			'table' => 1000,
			default => 10000,
		};
		$arguments['rowLimit'] = max(1, min(50000, $arguments['rowLimit'] ?? $defaultLimit));
		if ($arguments['xAxis'] === '' && in_array($viz, ['bar', 'line', 'scatter'], true))
		{
			$arguments['xAxis'] = $arguments['dimensions'][0] ?? '';
		}
		foreach ($arguments['metrics'] as $index => $metric)
		{
			$metric['column'] = trim($metric['column']);
			$metric['label'] = trim($metric['label']);
			$arguments['metrics'][$index] = $metric;
		}
		foreach ($arguments['filters'] as $index => $filter)
		{
			$filter['column'] = trim($filter['column']);
			$filter['operator'] = match ($filter['operator']) {
				'=' => '==',
				'<>' => '!=',
				default => $filter['operator'],
			};
			$arguments['filters'][$index] = $filter;
		}

		return $arguments;
	}

	private static function strings(array $values): array
	{
		return array_values(array_filter(array_map('trim', $values), static fn(string $value): bool => $value !== ''));
	}

	private static function defaults(array $schema, array $arguments): array
	{
		foreach ($schema['properties'] ?? [] as $key => $property)
		{
			if (!array_key_exists($key, $arguments) && array_key_exists('default', $property))
			{
				$arguments[$key] = $property['default'];
			}
			if (!array_key_exists($key, $arguments))
			{
				continue;
			}
			if (($property['type'] ?? null) === 'object' && $arguments[$key] instanceof \stdClass)
			{
				$arguments[$key] = get_object_vars($arguments[$key]);
			}
			if (isset($property['items']['properties']))
			{
				foreach ($arguments[$key] as $index => $item)
				{
					$record = $item instanceof \stdClass ? get_object_vars($item) : $item;
					$arguments[$key][$index] = self::defaults($property['items'], $record);
				}
			}
		}

		return $arguments;
	}
}
