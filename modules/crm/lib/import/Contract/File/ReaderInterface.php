<?php

namespace Bitrix\Crm\Import\Contract\File;

use Bitrix\Crm\Import\File\Header;
use Bitrix\Crm\Import\File\Row;
use Generator;

interface ReaderInterface
{
	/**
	 * @return Generator<Row>
	 */
	public function read(?int $limit = null): Generator;

	public function readRow(int $rowIndex): ?Row;

	public function setCurrentLine(int $line): self;

	public function getCurrentLine(): int;

	public function getPosition(): int;

	public function getFilesize(): int;

	public function rewind(): self;

	/**
	 * @return Header[]
	 */
	public function getHeaders(): array;
}
