<?php

namespace Bitrix\Crm\Import\File\Writer;

use Bitrix\Crm\Import\Contract\File\WriterInterface;
use Bitrix\Crm\Import\File\Row;

final class NullWriter implements WriterInterface
{
	public function write(Row $row): WriterInterface
	{
		return $this;
	}

	public function isFileEmpty(): bool
	{
		return true;
	}

	public function writeHeaders(array $headers): self
	{
		return $this;
	}
}
