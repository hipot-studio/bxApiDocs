<?php

namespace Bitrix\Crm\Import\File\Writer;

use Bitrix\Crm\Import\Contract\File\WriterInterface;
use Bitrix\Crm\Import\Enum\Delimiter;
use Bitrix\Crm\Import\File\Header;
use Bitrix\Crm\Import\File\Row;
use Bitrix\Crm\Import\File\RowValue;
use SplFileObject;

final class CSVWriter implements WriterInterface
{
	private readonly SplFileObject $file;

	private bool $isFirstRowHasHeaders = false;
	private Delimiter $delimiter = Delimiter::Semicolon;

	public function __construct(
		private readonly string $filepath,
	)
	{
		$this->file = new SplFileObject($this->filepath, 'a');
		$this->file->setCsvControl($this->delimiter->getSymbol());
	}

	public function setIsFirstRowHasHeaders(bool $isFirstRowHasHeaders): self
	{
		$this->isFirstRowHasHeaders = $isFirstRowHasHeaders;

		return $this;
	}

	public function setDelimiter(Delimiter $delimiter): self
	{
		$this->delimiter = $delimiter;

		return $this;
	}

	public function write(Row $row): self
	{
		if (SITE_CHARSET === 'UTF-8' && $this->isFileEmpty())
		{
			// add UTF-8 BOM marker
			$this->file->fwrite(chr(239).chr(187).chr(191));
		}

		$this->file->fputcsv($row->toArray());

		$this->flush();

		return $this;
	}

	public function isFileEmpty(): bool
	{
		return filesize($this->filepath) === 0;
	}

	/**
	 * @param Header[] $headers
	 * @return $this
	 */
	public function writeHeaders(array $headers): self
	{
		if ($this->isFirstRowHasHeaders && $this->isFileEmpty())
		{
			$row = new Row(
				rowIndex: 0,
				values: [],
			);

			foreach ($headers as $header)
			{
				$row->setValue(
					new RowValue(
						columnIndex: $header->getColumnIndex(),
						value: $header->getTitle(),
					),
				);
			}

			return $this->write($row);
		}

		return $this;
	}

	private function flush(): void
	{
		$this->file->fflush();
		clearstatcache(true, $this->filepath);
	}
}
