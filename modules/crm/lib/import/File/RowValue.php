<?php

namespace Bitrix\Crm\Import\File;

use Bitrix\Main\Type\Contract\Arrayable;
use JsonSerializable;

final class RowValue implements JsonSerializable, Arrayable
{
	public function __construct(
		private readonly int $columnIndex,
		private mixed $value,
	)
	{
	}

	public function getColumnIndex(): int
	{
		return $this->columnIndex;
	}

	public function getValue(): mixed
	{
		return $this->value;
	}

	public function setValue(mixed $value): self
	{
		$this->value = $value;

		return $this;
	}

	public function toArray(): array
	{
		return [
			'columnIndex' => $this->columnIndex,
			'value' => $this->value,
		];
	}

	public function jsonSerialize(): array
	{
		return $this->toArray();
	}
}
