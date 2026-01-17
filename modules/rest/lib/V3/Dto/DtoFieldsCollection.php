<?php

namespace Bitrix\Rest\V3\Dto;

use Bitrix\Main\SystemException;
use Traversable;

class DtoFieldsCollection implements \IteratorAggregate, \Countable, \ArrayAccess
{
	private array $fieldsOrdered = [];

	private array $fieldsIndex = [];

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

	public function getIterator(): Traversable
	{
		return new \ArrayIterator($this->fieldsOrdered);
	}

	public function count(): int
	{
		return count($this->fieldsOrdered);
	}

	public function add(DtoField $field): void
	{
		if (!isset($this->fieldsIndex[$field->getPropertyName()]))
		{
			$this->fieldsOrdered[] = $this->fieldsIndex[$field->getPropertyName()] = $field;
		}
	}

	public function offsetExists($offset): bool
	{
		return isset($this->fieldsIndex[$offset]);
	}

	public function offsetGet($offset): ?DtoField
	{
		return $this->fieldsIndex[$offset] ?? null;
	}

	public function offsetSet($offset, $value): void
	{
		if (!$value instanceof DtoField)
		{
			throw new SystemException('Value must be an instance of DtoField');
		}

		$name = $offset ?? $value->getPropertyName();

		if (!isset($this->fieldsIndex[$name]))
		{
			$this->fieldsOrdered[] = $value;
		}
		$this->fieldsIndex[$name] = $value;
	}

	public function offsetUnset($offset): void
	{
		if (isset($this->fieldsIndex[$offset]))
		{
			unset($this->fieldsIndex[$offset]);

			$this->fieldsOrdered = array_filter(
				$this->fieldsOrdered,
				fn(DtoField $field) => $field->getPropertyName() !== $offset,
			);
		}
	}
}
