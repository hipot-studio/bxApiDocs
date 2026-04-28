<?php

namespace Bitrix\Crm\Import\Dto\UI\Table;

use Bitrix\Main\Type\Contract\Arrayable;
use JsonSerializable;

final class Row implements Arrayable, JsonSerializable
{
	public function __construct(
		/** @var RowValue[] */
		private readonly array $values = [],
		private readonly array $errors = [],
	)
	{
	}

	public static function fromReaderRow(\Bitrix\Crm\Import\File\Row $row, array $errors): self
	{
		$values = [];
		foreach ($row->getValues() as $value)
		{
			$values[] = new RowValue(
				columnIndex: $value->getColumnIndex(),
				value: $value->getValue(),
			);
		}

		return new self(
			values: $values,
			errors: $errors,
		);
	}

	public function toArray(): array
	{
		return [
			'values' => array_map(static fn (RowValue $row) => $row->toArray(), $this->values),
			'errors' => $this->errors,
		];
	}

	public function jsonSerialize(): array
	{
		return $this->toArray();
	}
}
