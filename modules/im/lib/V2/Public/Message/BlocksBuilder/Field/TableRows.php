<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Public\Message\BlocksBuilder\Field;

class TableRows extends AbstractField
{
	protected array $rows = [];

	public function addRow(string $firstColumn, ?string $secondColumn = null): self
	{
		$row = [['text' => $firstColumn]];
		if (isset($secondColumn))
		{
			$row[] = ['text' => $secondColumn];
		}

		$this->rows[] = $row;

		return $this;
	}

	public function jsonSerialize(): array
	{
		return $this->rows;
	}

	public static function create(): self
	{
		return new self();
	}
}
