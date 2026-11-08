<?php

namespace Bitrix\BIConnector\DataSourceConnector\Aggregation;

/**
 * Typed literal of a HAVING node (design §2.6).
 *
 * The value is kept as the received string; the grammar of design §2.6 is checked by the validator,
 * which knows the type of the reference the literal is compared against.
 */
final class Literal
{
	public function __construct(
		public readonly TypeSpec $type,
		public readonly string $value,
	)
	{
	}

	/**
	 * @throws AggregationException
	 */
	public static function fromArray(mixed $raw): self
	{
		if (!is_array($raw))
		{
			throw AggregationException::badRequest('literal must be an object');
		}

		foreach (array_keys($raw) as $key)
		{
			if ($key !== 'type' && $key !== 'value')
			{
				throw AggregationException::badRequest("unexpected literal key {$key}");
			}
		}

		if (!array_key_exists('type', $raw) || !array_key_exists('value', $raw))
		{
			throw AggregationException::badRequest('literal requires type and value');
		}

		$value = $raw['value'];
		if (!is_string($value) || $value === '')
		{
			throw AggregationException::badRequest('literal.value must be a non-empty string');
		}

		return new self(TypeSpec::fromArray($raw['type']), $value);
	}

	public function toArray(): array
	{
		return [
			'type' => $this->type->toArray(),
			'value' => $this->value,
		];
	}
}
