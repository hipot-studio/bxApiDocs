<?php

namespace Bitrix\Crm\Import\Dto\Entity\FieldBindings;

use Bitrix\Main\Type\Contract\Arrayable;
use JsonSerializable;

final class Binding implements JsonSerializable, Arrayable
{
	public function __construct(
		private readonly string $fieldId,
		private readonly ?int $columnIndex,
	)
	{
	}

	public function getFieldId(): string
	{
		return $this->fieldId;
	}

	public function getColumnIndex(): ?int
	{
		return $this->columnIndex;
	}

	public static function tryFromArray(array $rawBinding): ?self
	{
		$fieldId = $rawBinding['fieldId'] ?? null;
		if (
			!is_string($fieldId)
			|| empty($fieldId)
		)
		{
			return null;
		}

		$columnIndex = $rawBinding['columnIndex'] ?? null;
		$columnIndex = is_numeric($columnIndex) ? (int)$columnIndex : null;

		return new self($fieldId, $columnIndex);
	}

	public function toArray(): array
	{
		return [
			'fieldId' => $this->fieldId,
			'columnIndex' => $this->columnIndex,
		];
	}

	public function jsonSerialize(): array
	{
		return $this->toArray();
	}
}
