<?php

namespace Bitrix\Crm\Import\Contract\File;

use Bitrix\Crm\Import\File\Header;
use Bitrix\Crm\Import\File\Row;

interface WriterInterface
{
	public function write(Row $row): self;

	public function isFileEmpty(): bool;

	/**
	 * @param Header[] $headers
	 * @return self
	 */
	public function writeHeaders(array $headers): self;
}
