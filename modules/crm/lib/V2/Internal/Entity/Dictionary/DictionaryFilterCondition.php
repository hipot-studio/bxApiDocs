<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Entity\Dictionary;

use Bitrix\Main\ArgumentException;

/**
 * One comparison of a dictionary field with a value: the leaf of {@see DictionaryFilter}.
 *
 * A condition that exists is a condition the domain can translate. An operator the field does not
 * support, and a value that is not of the field or does not fit the shape the operator needs, are refused
 * here by {@see ArgumentException} - a mistake of the caller rather than an outcome of an operation. The
 * transport does not let such a condition through, so nothing below has to check it a second time.
 *
 * An empty set for `in` is left to be interpreted rather than refused: nothing satisfies such a
 * condition, and the repository translates it into a condition nothing satisfies.
 *
 * @internal
 */
final readonly class DictionaryFilterCondition
{
	/** @param string|int|bool|array<int, string|int|bool> $value */
	public function __construct(
		public DictionaryField $field,
		public DictionaryFilterOperator $operator,
		public string|int|bool|array $value,
	)
	{
		if (!$field->supportsOperator($operator))
		{
			throw new ArgumentException(
				"Dictionary field {$field->value} cannot be filtered by: {$operator->value}",
				'operator',
			);
		}

		$this->assertValueFitsOperator();
	}

	private function assertValueFitsOperator(): void
	{
		if ($this->operator->expectsRange())
		{
			$this->assertValueSet(2);

			return;
		}

		if ($this->operator->expectsList())
		{
			$this->assertValueSet(null);

			return;
		}

		if (is_array($this->value) || !$this->field->acceptsValue($this->value))
		{
			throw $this->valueRefusal("a single value of {$this->field->value}");
		}
	}

	private function assertValueSet(?int $size): void
	{
		if (!is_array($this->value) || !array_is_list($this->value))
		{
			throw $this->valueRefusal('a list of values');
		}

		if ($size !== null && count($this->value) !== $size)
		{
			throw $this->valueRefusal("a list of {$size} values");
		}

		foreach ($this->value as $item)
		{
			if (!$this->field->acceptsValue($item))
			{
				throw $this->valueRefusal("values of {$this->field->value}");
			}
		}
	}

	private function valueRefusal(string $expected): ArgumentException
	{
		return new ArgumentException(
			"Operator {$this->operator->value} of the dictionary filter expects {$expected}",
			'value',
		);
	}
}
