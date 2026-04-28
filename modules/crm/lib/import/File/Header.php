<?php

namespace Bitrix\Crm\Import\File;

use Bitrix\Main\Type\Contract\Arrayable;
use JsonSerializable;

final class Header implements JsonSerializable, Arrayable
{
	public function __construct(
		private int $columnIndex,
		private string $title,
	)
	{
	}

	public function getColumnIndex(): int
	{
		return $this->columnIndex;
	}

	public function setColumnIndex(int $columnIndex): self
	{
		$this->columnIndex = $columnIndex;

		return $this;
	}

	public function getTitle(): string
	{
		return $this->title;
	}

	public function setTitle(string $title): self
	{
		$this->title = $title;

		return $this;
	}

	public function toArray(): array
	{
		return [
			'columnIndex' => $this->getColumnIndex(),
			'title' => $this->getTitle(),
		];
	}

	public function jsonSerialize(): array
	{
		return $this->toArray();
	}
}
