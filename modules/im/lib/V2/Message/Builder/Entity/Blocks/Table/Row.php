<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Builder\Entity\Blocks\Table;

class Row implements \JsonSerializable
{
	protected array $columns;

	private function __construct(array $rowData)
	{
		$this->columns = $rowData;
	}

	public static function create(array $rowData): self
	{
		return new self($rowData);
	}

	public function getPayloadText(): ?string
	{
		$result = '';

		foreach ($this->columns as $column)
		{
			$result .= $column['text'] . PHP_EOL;
		}

		return trim($result);
	}

	public function jsonSerialize(): array
	{
		$result = [];

		foreach ($this->columns as $column)
		{
			$result[]['text'] = \Bitrix\Im\Text::parse($column['text']);
		}

		return $result;
	}
}
