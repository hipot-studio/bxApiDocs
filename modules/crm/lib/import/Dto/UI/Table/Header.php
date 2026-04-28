<?php

namespace Bitrix\Crm\Import\Dto\UI\Table;

use Bitrix\Main\Type\Contract\Arrayable;
use JsonSerializable;

final class Header implements JsonSerializable, Arrayable
{
	public function __construct(
		private readonly int $columnIndex,
		private readonly string $title,
	)
	{
	}

	public function toArray(): array
	{
		return [
			'columnIndex' => $this->columnIndex,
			'title' => $this->title,
		];
	}

	public function jsonSerialize(): array
	{
		return $this->toArray();
	}
}
