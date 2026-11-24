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
		if ($schema === [] || !self::isJson($arguments))
		{
			return Refusal::error(FailureCode::InvalidArgument, 'Invalid BI Builder arguments.');
		}
		$violation = self::firstViolation($schema, $arguments);
		if ($violation !== null)
		{
			$message = $violation->isNested()
				? ArgumentViolationFormatter::format($violation) : 'Invalid BI Builder arguments.';

			return Refusal::error(FailureCode::InvalidArgument, $message)->setData([
				'argumentViolation' => ArgumentViolationFormatter::path($violation->path) . '.' . $violation->facet,
			]);
		}
		$rule = self::ruleViolation($uri, $arguments);
		if ($rule !== null)
		{
			return Refusal::error(FailureCode::InvalidArgument,
				ArgumentViolationFormatter::rule($rule) ?? 'Invalid BI Builder arguments.')->setData($rule);
		}

		return new Result();
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
		foreach ($arguments['filters'] ?? [] as $index => $filter)
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
			$between = ['column' => trim($filter['column']), 'index' => $index];
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
				return ['argumentViolation' => 'between.list'] + $between;
			}
			foreach ($value as $boundIndex => $bound)
			{
				if ($bound === null || (is_scalar($bound) && trim((string)$bound) === ''))
				{
					return ['argumentViolation' => 'between.bounds', 'boundIndex' => $boundIndex] + $between;
				}
				if (!is_scalar($bound))
				{
					return ['argumentViolation' => 'between.boundType', 'boundIndex' => $boundIndex] + $between;
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

	private static function firstViolation(array $schema, mixed $value, array $path = []): ?ArgumentViolation
	{
		if (isset($schema['$ref']))
		{
			return null;
		}
		if (isset($schema['anyOf']))
		{
			$best = null;
			$types = [];
			foreach ($schema['anyOf'] as $alternative)
			{
				$violation = self::firstViolation($alternative, $value, $path);
				if ($violation === null)
				{
					return null;
				}
				$type = $alternative['type'] ?? '';
				$types[] = $type;
				if (self::hasType($type, $value)
					&& ($best === null || count($violation->path) > count($best->path)))
				{
					$best = $violation;
				}
			}

			return $best ?? new ArgumentViolation($path, 'type', ['type' => array_values(array_unique($types))]);
		}
		$type = $schema['type'] ?? '';
		if (!self::hasType($type, $value))
		{
			return new ArgumentViolation($path, 'type', $schema);
		}
		if (isset($schema['enum']) && !in_array($value, $schema['enum'], true))
		{
			return new ArgumentViolation($path, 'enum', $schema);
		}
		$facet = null;
		if ($type === 'integer' || $type === 'number')
		{
			$facet = match (true)
			{
				isset($schema['minimum']) && $value < $schema['minimum'] => 'minimum',
				isset($schema['maximum']) && $value > $schema['maximum'] => 'maximum',
				default => null,
			};
		}
		elseif ($type === 'string')
		{
			$length = mb_strlen($value);
			$facet = match (true)
			{
				isset($schema['minLength']) && ($length < $schema['minLength']
					|| ($schema['minLength'] > 0 && trim($value) === '')) => 'minLength',
				isset($schema['maxLength']) && $length > $schema['maxLength'] => 'maxLength',
				isset($schema['pattern']) && preg_match('~' . $schema['pattern'] . '~uD', $value) !== 1 => 'pattern',
				default => null,
			};
		}
		elseif ($type === 'array')
		{
			$count = count($value);
			$facet = match (true)
			{
				isset($schema['minItems']) && $count < $schema['minItems'] => 'minItems',
				isset($schema['maxItems']) && $count > $schema['maxItems'] => 'maxItems',
				isset($schema['x-max-unique-items']) && array_filter($value, 'is_int') === $value
					&& count(array_unique($value)) > $schema['x-max-unique-items'] => 'x-max-unique-items',
				default => null,
			};
		}
		if ($facet !== null)
		{
			return new ArgumentViolation($path, $facet, $schema);
		}
		if ($type === 'array')
		{
			foreach ($value as $index => $item)
			{
				$violation = self::firstViolation($schema['items'], $item, [...$path, $index]);
				if ($violation !== null)
				{
					return $violation;
				}
			}
		}
		if ($type === 'object')
		{
			$record = is_array($value) ? $value : get_object_vars($value);
			foreach ($schema['required'] ?? [] as $required)
			{
				if (!array_key_exists($required, $record))
				{
					return new ArgumentViolation([...$path, $required], 'required', $schema['properties'][$required] ?? []);
				}
			}
			foreach ($schema['properties'] ?? [] as $key => $property)
			{
				if (array_key_exists($key, $record))
				{
					$violation = self::firstViolation($property, $record[$key], [...$path, (string)$key]);
					if ($violation !== null)
					{
						return $violation;
					}
				}
			}
			$extra = array_diff_key($record, $schema['properties'] ?? []);
			ksort($extra, SORT_STRING);
			foreach ($extra as $key => $item)
			{
				$property = $schema['additionalProperties'] ?? true;
				if ($property === false)
				{
					return new ArgumentViolation([...$path, (string)$key], 'additionalProperties', []);
				}
				if (is_array($property))
				{
					$violation = self::firstViolation($property, $item, [...$path, (string)$key]);
					if ($violation !== null)
					{
						return $violation;
					}
				}
			}
		}

		return null;
	}

	private static function hasType(string $type, mixed $value): bool
	{
		return match ($type)
		{
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
	}
}
