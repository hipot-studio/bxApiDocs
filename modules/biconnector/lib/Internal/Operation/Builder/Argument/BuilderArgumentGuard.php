<?php

namespace Bitrix\BIConnector\Internal\Operation\Builder\Argument;

use Bitrix\BIConnector\Internal\Operation\Builder\FailureCode;
use Bitrix\BIConnector\Internal\Operation\Builder\Refusal;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Result;
use Bitrix\Main\Web\Json;

final class BuilderArgumentGuard
{
	public static function validate(string $uri, array $arguments): Result
	{
		$schema = BuilderArgumentContract::forMethod($uri);
		$violation = null;
		if (
			$schema === []
			|| !self::isJson($arguments)
			|| !self::matches($schema, $arguments)
			|| ($violation = self::ruleViolation($uri, $arguments)) !== null
		)
		{
			$result = Refusal::error(FailureCode::InvalidArgument, 'Invalid BI Builder arguments.');
			$violation ??= self::facetViolation($schema, $arguments);
			if ($violation !== null && $violation !== [])
			{
				$result->setData($violation);
			}

			return $result;
		}

		return new Result();
	}

	/**
	 * The first root argument that breaks a facet the MCP entry used to refuse with its own text.
	 *
	 * The violation is named `<argument>.<facet>`, e.g. `chartId.minimum`.
	 */
	private static function facetViolation(array $schema, array $arguments): ?array
	{
		foreach ($schema['properties'] ?? [] as $name => $property)
		{
			$value = $arguments[$name] ?? null;
			$facet = match (true)
			{
				is_int($value) && isset($property['minimum']) && $value < $property['minimum'] => 'minimum',
				is_string($value) && isset($property['minLength']) && $property['minLength'] > 0
					&& (mb_strlen($value) < $property['minLength'] || trim($value) === '') => 'minLength',
				is_array($value) && isset($property['minItems']) && count($value) < $property['minItems'] => 'minItems',
				is_array($value) && isset($property['x-max-unique-items'])
					&& array_filter($value, 'is_int') === $value
					&& count(array_unique($value)) > $property['x-max-unique-items'] => 'x-max-unique-items',
				default => null,
			};
			if ($facet !== null)
			{
				return ['argumentViolation' => $name . '.' . $facet];
			}
		}

		return null;
	}

	/**
	 * The first rule the arguments break, or null when they keep all of them.
	 *
	 * A violation names the broken rule in `argumentViolation` with the details the MCP entry
	 * needs to repeat its previous refusal; an empty violation is a rule without such a refusal.
	 */
	private static function ruleViolation(string $uri, array $arguments): ?array
	{
		$method = substr($uri, strlen('biconnector.builder.'));
		if ($method === 'dashboard.content.set')
		{
			if (!array_key_exists('charts', $arguments) && !array_key_exists('filters', $arguments))
			{
				return ['argumentViolation' => 'content.nothing'];
			}
			if (array_key_exists('filters', $arguments))
			{
				$hasPeriod = false;
				foreach ($arguments['filters'] as $index => $filter)
				{
					$filter = (array)$filter;
					if ($filter['filterType'] === 'time_range')
					{
						$hasPeriod = $hasPeriod || trim($filter['defaultValue'] ?? '') !== '';
					}
					elseif (!isset($filter['datasetId']) || trim($filter['column'] ?? '') === '')
					{
						return [
							'argumentViolation' => isset($filter['datasetId'])
								? 'content.filters.column'
								: 'content.filters.datasetId',
							'index' => $index,
							'filterType' => $filter['filterType'],
						];
					}
				}
				if (!$hasPeriod)
				{
					return ['argumentViolation' => 'content.filters.period'];
				}
			}
		}
		if ($method === 'chart.update'
			&& !array_key_exists('name', $arguments)
			&& !array_key_exists('description', $arguments))
		{
			return ['argumentViolation' => 'chart.update.nothing'];
		}
		if ($method === 'table.schema.get' && trim($arguments['tableName']) === '')
		{
			return ['argumentViolation' => 'tableName.blank'];
		}
		if ($method === 'dataset.addfromtable'
			&& array_filter($arguments['columns'], static fn(string $column): bool => trim($column) !== '') === [])
		{
			return ['argumentViolation' => 'columns.blank'];
		}
		if (!in_array($method, ['chart.add', 'chart.config.set'], true))
		{
			return null;
		}
		if (trim($arguments['temporalColumn']) === '')
		{
			return ['argumentViolation' => 'temporalColumn.blank'];
		}
		$viz = $arguments['vizType'];
		if ($viz !== 'table' && empty($arguments['metrics']))
		{
			return ['argumentViolation' => 'metrics.required', 'vizType' => $viz];
		}
		$dimensions = array_filter($arguments['dimensions'] ?? [], static fn(string $value): bool => trim($value) !== '');
		if (($viz === 'pie' || (in_array($viz, ['bar', 'line', 'scatter'], true) && ($arguments['xAxis'] ?? '') === ''))
			&& $dimensions === [])
		{
			return ['argumentViolation' => 'dimensions.required', 'vizType' => $viz];
		}
		foreach ($arguments['filters'] ?? [] as $filter)
		{
			$filter = (array)$filter;
			if (($filter['operator'] ?? '=') !== 'between')
			{
				continue;
			}
			$value = $filter['value'] ?? null;
			if (is_string($value) && trim($value) !== '')
			{
				continue;
			}
			$between = ['column' => trim($filter['column'])];
			if (!is_array($value))
			{
				return ['argumentViolation' => is_string($value) ? 'between.blank' : 'between.type'] + $between;
			}
			if (count($value) !== 2)
			{
				return ['argumentViolation' => 'between.pair'] + $between;
			}
			if (!array_is_list($value))
			{
				return [];
			}
			foreach ($value as $bound)
			{
				if ($bound === null || (is_scalar($bound) && trim((string)$bound) === ''))
				{
					return ['argumentViolation' => 'between.bounds'] + $between;
				}
				if (!is_scalar($bound))
				{
					return [];
				}
			}
		}

		return null;
	}

	private static function isJson(array $value): bool
	{
		$pending = [[$value, null, 0, null, false]];
		$active = [];
		$completed = [];
		$heights = [];
		$nextId = 1;
		try
		{
			while ($pending !== [])
			{
				[$current, $identity, $id, $parent, $finished] = array_pop($pending);
				if ($finished)
				{
					$height = $heights[$id];
					if ($identity !== null)
					{
						unset($active[$identity]);
						$completed[$identity] = $height;
					}
				}
				elseif ($identity !== null && isset($active[$identity]))
				{
					return false;
				}
				elseif ($identity !== null && isset($completed[$identity]))
				{
					$height = $completed[$identity];
				}
				else
				{
					$isObject = is_object($current);
					if ($isObject && get_class($current) !== \stdClass::class)
					{
						return false;
					}
					$record = $isObject ? get_object_vars($current) : $current;
					$shallow = [];
					$children = [];
					foreach ($record as $key => $item)
					{
						if (is_array($item) || is_object($item))
						{
							$reference = \ReflectionReference::fromArrayElement($record, $key);
							$childIdentity = is_object($item)
								? 'object:' . spl_object_id($item)
								: ($reference === null ? null : 'reference:' . $reference->getId());
							$children[] = [$item, $childIdentity, $nextId++, $id, false];
							$shallow[$key] = null;
						}
						else
						{
							$shallow[$key] = $item;
						}
					}
					// Validate every scalar and key without expanding shared container edges.
					Json::encode($isObject ? (object)$shallow : $shallow);
					if ($identity !== null)
					{
						$active[$identity] = true;
					}
					$heights[$id] = 1;
					$pending[] = [null, $identity, $id, $parent, true];
					foreach ($children as $child)
					{
						$pending[] = $child;
					}

					continue;
				}
				if ($parent !== null)
				{
					$heights[$parent] = max($heights[$parent], $height + 1);
				}
				unset($heights[$id]);
			}
			// Let Main Json enforce its native depth on the longest container path.
			$witness = null;
			for ($depth = 0; $depth < $height; $depth++)
			{
				$witness = [$witness];
			}
			Json::encode($witness);
		}
		catch (ArgumentException)
		{
			return false;
		}

		return true;
	}

	private static function matches(array $schema, mixed $value): bool
	{
		if (isset($schema['$ref']))
		{
			return true;
		}
		if (isset($schema['anyOf']))
		{
			foreach ($schema['anyOf'] as $alternative)
			{
				if (self::matches($alternative, $value))
				{
					return true;
				}
			}

			return false;
		}
		$type = $schema['type'] ?? '';
		$typed = match ($type) {
			'integer' => is_int($value),
			'number' => is_int($value) || is_float($value),
			'string' => is_string($value),
			'boolean' => is_bool($value),
			'null' => $value === null,
			'array' => is_array($value) && array_is_list($value),
			'object' => (is_array($value) && ($value === [] || !array_is_list($value)))
				|| (is_object($value) && get_class($value) === \stdClass::class),
			default => false,
		};
		if (!$typed || (isset($schema['enum']) && !in_array($value, $schema['enum'], true)))
		{
			return false;
		}
		if ($type === 'integer' || $type === 'number')
		{
			return (!isset($schema['minimum']) || $value >= $schema['minimum'])
				&& (!isset($schema['maximum']) || $value <= $schema['maximum']);
		}
		if ($type === 'string')
		{
			$length = mb_strlen($value);

			return (!isset($schema['minLength']) || $length >= $schema['minLength'])
				&& (!isset($schema['minLength']) || $schema['minLength'] === 0 || trim($value) !== '')
				&& (!isset($schema['maxLength']) || $length <= $schema['maxLength'])
				&& (!isset($schema['pattern']) || preg_match('~' . $schema['pattern'] . '~uD', $value) === 1);
		}
		if ($type === 'array')
		{
			$count = count($value);
			if ((isset($schema['minItems']) && $count < $schema['minItems'])
				|| (isset($schema['maxItems']) && $count > $schema['maxItems']))
			{
				return false;
			}
			foreach ($value as $item)
			{
				if (!self::matches($schema['items'], $item))
				{
					return false;
				}
			}

			return !isset($schema['x-max-unique-items'])
				|| count(array_unique($value)) <= $schema['x-max-unique-items'];
		}
		if ($type === 'object')
		{
			$record = is_array($value) ? $value : get_object_vars($value);
			foreach ($schema['required'] ?? [] as $required)
			{
				if (!array_key_exists($required, $record))
				{
					return false;
				}
			}
			foreach ($record as $key => $item)
			{
				$property = $schema['properties'][$key] ?? $schema['additionalProperties'] ?? true;
				if ($property === false || (is_array($property) && !self::matches($property, $item)))
				{
					return false;
				}
			}
		}

		return true;
	}
}
