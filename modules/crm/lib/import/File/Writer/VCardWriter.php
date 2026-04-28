<?php

namespace Bitrix\Crm\Import\File\Writer;

use Bitrix\Crm\Import\Contract\File\WriterInterface;
use Bitrix\Crm\Import\File\Row;
use SplFileObject;

final class VCardWriter implements WriterInterface
{
	private readonly SplFileObject $file;

	public function __construct(
		private readonly string $filepath,
	)
	{
		$this->file = new SplFileObject($this->filepath, 'a');
	}

	public function write(Row $row): self
	{
		$this->file->fwrite('BEGIN:VCARD' . PHP_EOL);

		foreach ($row->getValues() as $rowValue)
		{
			foreach ($rowValue->getValue() as $lineParts)
			{
				foreach ($lineParts as $linePart)
				{
					$this->file->fwrite($linePart . PHP_EOL);
				}
			}
		}

		$this->file->fwrite('END:VCARD' . PHP_EOL);

		$this->flush();

		return $this;
	}

	public function isFileEmpty(): bool
	{
		return filesize($this->filepath) <= 0;
	}

	public function writeHeaders(array $headers): self
	{
		return $this;
	}

	private function flush(): void
	{
		$this->file->fflush();
		clearstatcache(true, $this->filepath);
	}
}
