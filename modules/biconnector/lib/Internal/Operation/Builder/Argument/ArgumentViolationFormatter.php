<?php

namespace Bitrix\BIConnector\Internal\Operation\Builder\Argument;

final class ArgumentViolationFormatter
{
	public const PERIOD_MESSAGE = 'filters must contain a filter with filterType="time_range" and a non-empty '
		. 'defaultValue. Example: {"name":"Период","filterType":"time_range","defaultValue":"Last quarter"}.';

	public static function format(ArgumentViolation $violation): string
	{
		$path = self::path($violation->path);
		$schema = $violation->expected;
		$minimum = $schema['minimum'] ?? null;
		$maximum = $schema['maximum'] ?? null;
		$type = $schema['type'] ?? '';
		$numberType = $type === 'integer' ? 'an integer' : 'a number';
		$constraint = match ($violation->facet)
		{
			'required' => 'is required',
			'additionalProperties' => 'is not allowed',
			'type' => 'must be ' . self::typeDescription($type),
			'enum' => 'must be one of: ' . implode(', ', array_map(self::quoted(...), $schema['enum'])),
			'minimum', 'maximum' => match (true)
			{
				$minimum !== null && $maximum !== null => "must be {$numberType} from {$minimum} to {$maximum}",
				$minimum !== null => "must be {$numberType} not less than {$minimum}",
				default => "must be {$numberType} not greater than {$maximum}",
			},
			'minLength' => 'must be a non-empty string of at least ' . $schema['minLength'] . ' characters',
			'maxLength' => 'must be a string of at most ' . $schema['maxLength'] . ' characters',
			'pattern' => 'must be a string matching ' . self::quoted($schema['pattern']),
			'minItems' => 'must contain at least ' . $schema['minItems'] . ' items',
			'maxItems' => 'must contain at most ' . $schema['maxItems'] . ' items',
			'x-max-unique-items' => 'must contain at most ' . $schema['x-max-unique-items'] . ' unique items',
			default => 'does not match the expected format',
		};

		return $path . ' ' . $constraint . '.';
	}

	public static function path(array $segments): string
	{
		$path = '';
		foreach ($segments as $segment)
		{
			if (is_int($segment))
			{
				$path .= '[' . $segment . ']';
			}
			elseif (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $segment) === 1)
			{
				$path .= ($path === '' ? '' : '.') . $segment;
			}
			else
			{
				$path .= '[' . self::quoted($segment) . ']';
			}
		}

		return $path;
	}

	public static function identifier(string $value): string
	{
		if (!mb_check_encoding($value, 'UTF-8'))
		{
			return '';
		}
		// One extra codepoint preserves the truncation decision at the 128-character boundary.
		$value = mb_substr($value, 0, 129, 'UTF-8');
		$tokens = [];
		foreach (preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY) as $character)
		{
			$tokens[] = match ($character)
			{
				'`' => '\\u0060',
				"\n" => '\\n',
				"\r" => '\\r',
				"\t" => '\\t',
				default => preg_match('/\\p{Cc}/u', $character) === 1
					? sprintf('\\u%04X', mb_ord($character))
					: substr(self::quoted($character), 1, -1),
			};
		}
		if (mb_strlen(implode('', $tokens)) <= 128)
		{
			return implode('', $tokens);
		}
		$output = '';
		foreach ($tokens as $token)
		{
			if (mb_strlen($output . $token) > 127)
			{
				break;
			}
			$output .= $token;
		}

		return $output . '…';
	}

	public static function rule(array $violation): ?string
	{
		$rule = $violation['argumentViolation'] ?? '';
		$index = $violation['index'] ?? 0;
		if (in_array($rule, ['content.filters.datasetId', 'content.filters.column'], true))
		{
			$field = $rule === 'content.filters.datasetId' ? 'datasetId' : 'column';

			return 'filters[' . $index . '].' . $field . ' is required for filterType='
				. $violation['filterType'] . '.';
		}
		if ($rule === 'content.filters.period')
		{
			return self::PERIOD_MESSAGE;
		}
		if (in_array($rule, ['between.list', 'between.boundType'], true))
		{
			$path = ['filters', $index, 'value'];
			if (isset($violation['boundIndex']))
			{
				$path[] = $violation['boundIndex'];
			}

			return self::path($path) . ($rule === 'between.list'
				? ' must be an array of two scalar bounds.' : ' must be a non-empty scalar bound.');
		}
		$column = self::identifier($violation['column'] ?? '');
		$message = match ($rule)
		{
			'between.blank' => 'Filter on `' . $column . '` with operator=between requires a non-empty value '
				. '(e.g. "Last 6 months" or "2024-01-01 : 2024-03-31").',
			'between.pair' => 'Filter on `' . $column . '` with operator=between requires value to be '
				. 'a [from, to] array of two strings or a single Superset time expression string.',
			'between.bounds' => 'Filter on `' . $column . '` with operator=between: '
				. 'both `from` and `to` must be non-empty.',
			'between.type' => 'Filter on `' . $column . '` with operator=between requires a string value or '
				. '[from, to] array.',
			default => null,
		};
		if ($message === null)
		{
			return null;
		}
		$path = ['filters', $index, 'value'];
		if (isset($violation['boundIndex']))
		{
			$path[] = $violation['boundIndex'];
		}

		return self::path($path) . ': ' . $message;
	}

	private static function typeDescription(string|array $types): string
	{
		$types = (array)$types;

		return implode(' or ', array_map(static fn(string $type): string => match ($type)
		{
			'integer' => 'an integer',
			'object' => 'an object',
			'array' => 'an array',
			'null' => 'null',
			default => 'a ' . $type,
		}, $types));
	}

	private static function quoted(mixed $value): string
	{
		return str_replace('`', '\\u0060', json_encode(
			$value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP,
		));
	}
}
