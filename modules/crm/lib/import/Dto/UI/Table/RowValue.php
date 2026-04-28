<?php

namespace Bitrix\Crm\Import\Dto\UI\Table;

use Bitrix\Main\Type\Contract\Arrayable;

final class RowValue implements \JsonSerializable, Arrayable
{
	public function __construct(
		private readonly int $columnIndex,
		private readonly mixed $value,
	)
	{}

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