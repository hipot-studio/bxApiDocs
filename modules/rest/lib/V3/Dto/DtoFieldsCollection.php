<?php

namespace Bitrix\Rest\V3\Dto;

use Bitrix\Main\SystemException;
use Bitrix\Main\Type\Contract\Arrayable;
use Bitrix\Rest\V3\Dto\Meta\AbsentValue;
use Bitrix\Rest\V3\Dto\Meta\FieldMetaInterface;
use Bitrix\Rest\V3\Dto\Meta\FieldsMeta;
use Traversable;

/**
 * A collection made by fromMeta() is thin: it keeps the shared description of the fields and their values
 * by position. Handing out a DtoField or changing the set of fields turns it into DtoField objects
 * that carry the values, and from then on the collection works on them only.
 */
class DtoFieldsCollection implements \IteratorAggregate, \Countable, \ArrayAccess, Arrayable
{
	// the text PHP gives for DtoField::getValue() of a field without a value
	private const ABSENT_VALUE_ERROR = 'Typed property ' . DtoField::class
		. '::$value must not be accessed before initialization';

	/**
	 * @var DtoField[]
	 */
	private array $fieldsOrdered = [];

	/**
	 * @var DtoField[]
	 */
	private array $fieldsIndex = [];

	private ?FieldsMeta $meta = null;

	/**
	 * Values of a thin collection by position in the description, AbsentValue::Marker for a field without a value.
	 *
	 * @var list<mixed>
	 */
	private array $values = [];

	/**
	 * @param DtoField[] $fields
	 */
	public function __construct(array $fields = [])
	{
		foreach ($fields as $field)
		{
			$this->add($field);
		}
	}

	/**
	 * @internal
	 */
	public static function fromMeta(FieldsMeta $meta): self
	{
		$collection = new self();
		$collection->meta = $meta;
		$collection->values = $meta->emptyValues;

		return $collection;
	}

	public function getIterator(): Traversable
	{
		$this->materialize();

		return new \ArrayIterator($this->fieldsOrdered);
	}

	public function count(): int
	{
		if ($this->meta !== null)
		{
			return count($this->meta->list);
		}

		return count($this->fieldsOrdered);
	}

	public function add(DtoField $field): void
	{
		$this->materialize();

		if (!isset($this->fieldsIndex[$field->getPropertyName()]))
		{
			$this->fieldsOrdered[] = $this->fieldsIndex[$field->getPropertyName()] = $field;
		}
	}

	public function offsetExists($offset): bool
	{
		if ($this->meta !== null)
		{
			return isset($this->meta->index[$offset]);
		}

		return isset($this->fieldsIndex[$offset]);
	}

	public function offsetGet($offset): ?DtoField
	{
		if ($this->meta !== null)
		{
			if (!isset($this->meta->index[$offset]))
			{
				return null;
			}

			$this->materialize();
		}

		return $this->fieldsIndex[$offset] ?? null;
	}

	public function offsetSet($offset, $value): void
	{
		if (!$value instanceof DtoField)
		{
			throw new SystemException('Value must be an instance of DtoField');
		}

		$this->materialize();

		$name = $offset ?? $value->getPropertyName();

		if (!isset($this->fieldsIndex[$name]))
		{
			$this->fieldsOrdered[] = $value;
		}
		$this->fieldsIndex[$name] = $value;
	}

	public function offsetUnset($offset): void
	{
		$this->materialize();

		if (isset($this->fieldsIndex[$offset]))
		{
			unset($this->fieldsIndex[$offset]);

			$this->fieldsOrdered = array_filter(
				$this->fieldsOrdered,
				fn(DtoField $field) => $field->getPropertyName() !== $offset,
			);
		}
	}

	public function toArray()
	{
		if ($this->meta !== null)
		{
			return $this->meta->toArray();
		}

		$result = [];
		foreach ($this->fieldsOrdered as $field)
		{
			$result[] = $field->toArray();
		}

		return $result;
	}

	/**
	 * Descriptions of the fields in iteration order, keyed by the positions the positional API takes.
	 *
	 * @internal
	 * @return array<int, FieldMetaInterface>
	 */
	public function getMetaList(): array
	{
		if ($this->meta !== null)
		{
			return $this->meta->list;
		}

		return $this->fieldsOrdered;
	}

	/**
	 * @internal
	 */
	public function getMeta(string $name): ?FieldMetaInterface
	{
		if ($this->meta !== null)
		{
			$position = $this->meta->index[$name] ?? null;

			return $position === null ? null : $this->meta->list[$position];
		}

		return $this->fieldsIndex[$name] ?? null;
	}

	/**
	 * @internal
	 */
	public function isFieldValueSet(string $name): bool
	{
		if ($this->meta !== null)
		{
			$position = $this->meta->index[$name] ?? null;

			return $position !== null && $this->values[$position] !== AbsentValue::Marker;
		}

		return isset($this->fieldsIndex[$name]) && $this->fieldsIndex[$name]->isInitialized();
	}

	/**
	 * Returns null for an unknown name and throws \Error, as DtoField::getValue() does, for a field without a value.
	 *
	 * @internal
	 */
	public function getFieldValue(string $name): mixed
	{
		if ($this->meta !== null)
		{
			$position = $this->meta->index[$name] ?? null;

			return $position === null ? null : $this->getValueAt($position);
		}

		return isset($this->fieldsIndex[$name]) ? $this->fieldsIndex[$name]->getValue() : null;
	}

	/**
	 * Does nothing for an unknown name.
	 *
	 * @internal
	 */
	public function setFieldValue(string $name, mixed $value): void
	{
		if ($this->meta !== null)
		{
			$position = $this->meta->index[$name] ?? null;
			if ($position !== null)
			{
				$this->values[$position] = $value;
			}

			return;
		}

		if (isset($this->fieldsIndex[$name]))
		{
			$this->fieldsIndex[$name]->setValue($value);
		}
	}

	/**
	 * @internal
	 */
	public function isValueSetAt(int $position): bool
	{
		if ($this->meta !== null)
		{
			return isset($this->meta->list[$position]) && $this->values[$position] !== AbsentValue::Marker;
		}

		return isset($this->fieldsOrdered[$position]) && $this->fieldsOrdered[$position]->isInitialized();
	}

	/**
	 * @internal
	 */
	public function getValueAt(int $position): mixed
	{
		if ($this->meta !== null)
		{
			if (!isset($this->meta->list[$position]) || $this->values[$position] === AbsentValue::Marker)
			{
				throw new \Error(self::ABSENT_VALUE_ERROR);
			}

			return $this->values[$position];
		}

		return $this->fieldsOrdered[$position]->getValue();
	}

	/**
	 * @internal
	 */
	public function setValueAt(int $position, mixed $value): void
	{
		if ($this->meta !== null)
		{
			if (isset($this->meta->list[$position]))
			{
				$this->values[$position] = $value;
			}

			return;
		}

		$this->fieldsOrdered[$position]->setValue($value);
	}

	/**
	 * @internal
	 */
	public function isMaterialized(): bool
	{
		return $this->meta === null;
	}

	private function materialize(): void
	{
		if ($this->meta === null)
		{
			return;
		}

		$list = $this->meta->list;
		$values = $this->values;
		$this->meta = null;
		$this->values = [];

		foreach ($list as $position => $fieldMeta)
		{
			$field = $fieldMeta->toDtoField();
			if ($values[$position] !== AbsentValue::Marker)
			{
				$field->setValue($values[$position]);
			}

			$this->add($field);
		}
	}
}
