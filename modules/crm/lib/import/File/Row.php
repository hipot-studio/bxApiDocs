<?php

namespace Bitrix\Crm\Import\File;

use Bitrix\Main\Type\Contract\Arrayable;
use JsonSerializable;

final class Row implements JsonSerializable, Arrayable
{
	/**
	 * @var RowValue[]
	 */
	private array $values = [];

	/**
	 * @param int $rowIndex
	 * @param RowValue[] $values
	 */
	public function __construct(
		private readonly int $rowIndex,
		array $values = [],
	)
	{
		$this->setValues($values);
	}

	public function getValues(): array
	{
		return $this->values;
	}

	/**
	 * @param RowValue[] $values
	 * @return $this
	 */
	public function setValues(array $values): self
	{
		foreach ($values as $value)
		{
			$this->setValue($value);
		}

		return $this;
	}

	public function getValue(int $columnIndex): ?RowValue
	{
		return $this->values[$columnIndex] ?? null;
	}

	public function setValue(RowValue $value): self
	{
		$this->values[$value->getColumnIndex()] = $value;

		return $this;
	}

	public function getIndex(): int
	{
		return $this->rowIndex;
	}

	public function toArray(): array
	{
		$values = [];
		foreach ($this->values as $value)
		{
			$values[$value->getColumnIndex()] = $value->getValue();
		}

		return $values;
	}

	public function jsonSerialize(): array
	{
		return $this->toArray();
	}

	public static function fromArray(int $rowIndex, array $rawRow): self
	{
		$rowValues = [];
		foreach ($rawRow as $columnIndex => $value)
		{
			$rowValues[] = new RowValue($columnIndex, $value);
		}

		return new self($rowIndex, $rowValues);
	}
}
