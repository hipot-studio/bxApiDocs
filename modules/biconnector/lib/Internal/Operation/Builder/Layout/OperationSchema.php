<?php

namespace Bitrix\BIConnector\Internal\Operation\Builder\Layout;

final class OperationSchema
{
	private const HEADER_SIZES = ['SMALL_HEADER', 'MEDIUM_HEADER', 'LARGE_HEADER'];
	private const SHAPES = [
		'add_chart_to_new_row' => [
			'required' => ['chartId', 'title'],
			'optional' => ['width', 'height', 'parentId', 'index'],
		],
		'move_chart_before' => ['addresses' => ['', 'reference']],
		'move_chart_after' => ['addresses' => ['', 'reference']],
		'move_chart_to_row' => [
			'addresses' => [''],
			'required' => ['rowId'],
			'optional' => ['index'],
		],
		'move_chart_to_new_row' => [
			'addresses' => [''],
			'optional' => ['parentId', 'index'],
		],
		'move_chart_to_tab' => [
			'addresses' => [''],
			'required' => ['tabId'],
			'optional' => ['rowId', 'index'],
		],
		'swap_charts' => ['addresses' => ['first', 'second']],
		'resize_chart' => [
			'addresses' => [''],
			'optional' => ['width', 'height'],
			'update' => ['width', 'height'],
		],
		'rename_chart_title' => [
			'addresses' => [''],
			'required' => ['title'],
		],
		'add_header_before_chart' => [
			'addresses' => ['reference'],
			'required' => ['text'],
			'optional' => ['headerSize'],
		],
		'upsert_header_before_chart' => [
			'addresses' => ['reference'],
			'required' => ['text'],
			'optional' => ['headerSize'],
		],
		'update_header' => [
			'header' => true,
			'optional' => ['text', 'headerSize'],
			'update' => ['text', 'headerSize'],
		],
		'delete_header' => ['header' => true],
		'delete_empty_rows' => [],
		'repair_parent_paths' => [],
	];

	/** Returns the shared operations parameter schema for MCP and REST. */
	public static function jsonSchema(): array
	{
		$variants = [];
		foreach (self::SHAPES as $name => $shape)
		{
			$properties = [
				'operation' => [
					'type' => 'string',
					'const' => $name,
				],
			];
			$required = array_merge(['operation'], $shape['required'] ?? []);
			$constraints = [];
			foreach (array_merge($shape['required'] ?? [], $shape['optional'] ?? []) as $key)
			{
				$properties[$key] = self::fieldSchema($key);
			}
			foreach ($shape['addresses'] ?? [] as $prefix)
			{
				[$componentKey, $chartKey] = self::addressKeys($prefix);
				$properties[$componentKey] = self::fieldSchema($componentKey);
				$properties[$chartKey] = self::fieldSchema($chartKey);
				$constraints[] = [
					'oneOf' => [
						['required' => [$componentKey]],
						['required' => [$chartKey]],
					],
				];
			}
			if (isset($shape['header']))
			{
				$properties['componentId'] = self::fieldSchema('componentId');
				$properties['currentText'] = self::fieldSchema('currentText');
				$constraints[] = [
					'oneOf' => [
						['required' => ['componentId']],
						['required' => ['currentText']],
					],
				];
			}
			if (isset($shape['update']))
			{
				$constraints[] = [
					'anyOf' => array_map(static fn(string $key): array => ['required' => [$key]], $shape['update']),
				];
			}
			$variant = [
				'type' => 'object',
				'properties' => $properties,
				'required' => $required,
				'additionalProperties' => false,
			];
			if ($constraints !== [])
			{
				$variant['allOf'] = $constraints;
			}
			$variants[] = $variant;
		}

		return [
			'type' => 'array',
			'minItems' => 1,
			'maxItems' => 50,
			'items' => ['oneOf' => $variants],
		];
	}

	public static function normalize(array $op): array
	{
		$name = $op['operation'] ?? null;
		if (!is_string($name) || !isset(self::SHAPES[trim($name)]))
		{
			$label = is_string($name) ? $name : '(missing)';
			throw new LayoutOperationError('unknown_operation', 'Unknown operation: ' . $label . '.');
		}
		$name = trim($name);
		$op['operation'] = $name;
		$shape = self::SHAPES[$name];
		$keys = array_merge(['operation'], $shape['required'] ?? [], $shape['optional'] ?? []);
		foreach ($shape['addresses'] ?? [] as $prefix)
		{
			[$componentKey, $chartKey] = self::addressKeys($prefix);
			self::requireAddress($op, $componentKey, $chartKey);
			$keys[] = $componentKey;
			$keys[] = $chartKey;
		}
		if (isset($shape['header']))
		{
			self::requireAddress($op, 'componentId', 'currentText');
			$keys[] = 'componentId';
			$keys[] = 'currentText';
		}
		foreach (array_keys($op) as $key)
		{
			if (!in_array($key, $keys, true))
			{
				throw new LayoutOperationError('unknown_key', 'Unexpected operation field: ' . $key . '.');
			}
		}
		foreach ($shape['required'] ?? [] as $key)
		{
			if (!array_key_exists($key, $op))
			{
				throw new LayoutOperationError('required_field', 'Missing operation field: ' . $key . '.');
			}
		}
		if (isset($shape['update']) && array_intersect($shape['update'], array_keys($op)) === [])
		{
			throw new LayoutOperationError(
				'required_field',
				'Provide at least one of: ' . implode(', ', $shape['update']) . '.',
			);
		}
		foreach ($op as $key => $value)
		{
			if ($key === 'operation')
			{
				continue;
			}
			if (self::isIntegerKey($key))
			{
				$op[$key] = self::integer($value, $key);
				if (str_ends_with($key, 'ChartId') || $key === 'chartId')
				{
					if ($op[$key] <= 0)
					{
						throw new LayoutOperationError('invalid_field', $key . ' must be positive.');
					}
				}

				continue;
			}
			if (!is_string($value) || trim($value) === '' || mb_strlen(trim($value)) > 250)
			{
				throw new LayoutOperationError('invalid_field', $key . ' must be a nonempty string of at most 250 characters.');
			}
			$op[$key] = trim($value);
			if (str_ends_with($key, 'Id') && preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $op[$key]) !== 1)
			{
				throw new LayoutOperationError('invalid_field', $key . ' must be a valid component identifier.');
			}
			if ($key === 'headerSize' && !in_array($op[$key], self::HEADER_SIZES, true))
			{
				throw new LayoutOperationError('invalid_field', 'Invalid headerSize.');
			}
		}
		if ($name === 'add_chart_to_new_row')
		{
			$op += [
				'width' => 12,
				'height' => 50,
			];
		}
		if (in_array($name, ['add_header_before_chart', 'upsert_header_before_chart'], true))
		{
			$op += ['headerSize' => 'MEDIUM_HEADER'];
		}

		return $op;
	}

	private static function fieldSchema(string $key): array
	{
		if (self::isIntegerKey($key))
		{
			return [
				'oneOf' => [
					['type' => 'integer'],
					[
						'type' => 'string',
						'pattern' => '^[0-9]+$',
					],
				],
			];
		}
		if ($key === 'headerSize')
		{
			return [
				'type' => 'string',
				'enum' => self::HEADER_SIZES,
			];
		}
		if (str_ends_with($key, 'Id'))
		{
			$schema = [
				'type' => 'string',
				'pattern' => '^[A-Za-z0-9_-]{1,64}$',
			];
			if ($key === 'parentId')
			{
				$schema['description']
					= 'When omitted, uses GRID_ID for ROOT -> GRID or the first TAB in TABS.children for ROOT -> TABS.';
			}

			return $schema;
		}

		return [
			'type' => 'string',
			'minLength' => 1,
			'maxLength' => 250,
		];
	}

	private static function isIntegerKey(string $key): bool
	{
		return in_array($key, ['chartId', 'width', 'height', 'index'], true) || str_ends_with($key, 'ChartId');
	}

	private static function addressKeys(string $prefix): array
	{
		return $prefix === '' ? ['componentId', 'chartId'] : [$prefix . 'ComponentId', $prefix . 'ChartId'];
	}

	private static function requireAddress(array $op, string $first, string $second): void
	{
		if (array_key_exists($first, $op) === array_key_exists($second, $op))
		{
			throw new LayoutOperationError('invalid_address', 'Provide exactly one of ' . $first . ' or ' . $second . '.');
		}
	}

	private static function integer(mixed $value, string $key): int
	{
		if (is_int($value))
		{
			return $value;
		}
		if (is_string($value) && preg_match('/^[0-9]+$/D', $value) === 1)
		{
			$digits = ltrim($value, '0');
			$max = (string)PHP_INT_MAX;
			if (strlen($digits) < strlen($max) || (strlen($digits) === strlen($max) && strcmp($digits, $max) <= 0))
			{
				return (int)$value;
			}
		}

		throw new LayoutOperationError('invalid_field', $key . ' must be an integer or a string of digits.');
	}
}
