<?php

namespace Bitrix\Crm\Import\Dto\UI;

use Bitrix\Crm\Import\Contract\File\ReaderInterface;
use Bitrix\Crm\Import\Dto\UI\Table\Header;
use Bitrix\Crm\Import\Dto\UI\Table\Row;
use Bitrix\Main\Type\Contract\Arrayable;
use JsonSerializable;

final class Table implements Arrayable, JsonSerializable
{
	/** @var Row[] */
	private array $rows = [];

	public function __construct(
		/** @var Header[] */
		private readonly array $headers,
	)
	{
	}

	public static function byReader(ReaderInterface $reader, int $readLimit = 0): self
	{
		$headers = [];
		foreach ($reader->getHeaders() as $header)
		{
			$headers[] = new Header(
				columnIndex: $header->getColumnIndex(),
				title: $header->getTitle(),
			);
		}

		$instance = new self($headers);
		foreach ($reader->read($readLimit) as $row)
		{
			$instance->addRow(Row::fromReaderRow($row, errors: []));
		}

		return $instance;
	}

	public function addRow(Row $row): self
	{
		$this->rows[] = $row;

		return $this;
	}

	public function toArray(): array
	{
		return [
			'headers' => array_map(static fn (Header $header) => $header->toArray(), $this->headers),
			'rows' => array_map(static fn (Row $row) => $row->toArray(), $this->rows),
		];
	}

	public function jsonSerialize(): array
	{
		return $this->toArray();
	}
}
