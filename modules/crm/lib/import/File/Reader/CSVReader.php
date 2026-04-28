<?php

namespace Bitrix\Crm\Import\File\Reader;

use Bitrix\Crm\Import\Contract\File\ReaderInterface;
use Bitrix\Crm\Import\Enum\Delimiter;
use Bitrix\Crm\Import\File\Header;
use Bitrix\Crm\Import\File\Row;
use Bitrix\Main\Localization\Loc;
use Generator;
use SplFileObject;

final class CSVReader implements ReaderInterface
{
	private ?SplFileObject $file;

	private bool $isSkipEmptyColumns = false;
	private bool $isFirstRowHasHeaders = true;

	public function __construct(
		private readonly string $filename,
	)
	{
		$this->file = new SplFileObject($this->filename, 'r');
		$this->file->setFlags(SplFileObject::READ_CSV);
	}

	public function getCurrentLine(): int
	{
		return $this->file->key();
	}

	public function setCurrentLine(int $line): self
	{
		$this->file->seek($line);

		return $this;
	}

	public function getPosition(): int
	{
		return $this->file->ftell();
	}

	public function read(?int $limit = null): Generator
	{
		$n = 0;

		while (!$this->file->eof())
		{
			$row = $this->file->fgetcsv();
			if ($row === [null] || $row === false)
			{
				continue;
			}

			if ($this->isFirstRowHasHeaders && $this->file->key() === 0)
			{
				continue;
			}

			if ($limit !== null && $n >= $limit)
			{
				break;
			}

			$n++;

			yield Row::fromArray($this->file->key(), $row);
		}
	}

	public function readRow(int $rowIndex): ?Row
	{
		if ($rowIndex < 0)
		{
			return null;
		}

		$this->file->seek($rowIndex);
		$row = $this->file->fgetcsv();

		return is_array($row) && $row !== [null]
			? Row::fromArray($this->file->key(), $row)
			: null;
	}

	public function rewind(): self
	{
		$this->setCurrentLine(0);

		return $this;
	}

	public function getFilesize(): int
	{
		return filesize($this->filename);
	}

	public function isEndOfFile(): bool
	{
		return $this->file->eof();
	}

	public function close(): void
	{
		$this->file = null;
	}

	public function getHeaders(): array
	{
		$fileHeaders = [];

		$row = $this->readRow(0);
		if ($row === null)
		{
			return $fileHeaders;
		}

		foreach ($row->getValues() as $rowValue)
		{
			if (!$this->isFirstRowHasHeaders)
			{
				$fileHeaders[] = new Header(
					columnIndex: $rowValue->getColumnIndex(),
					title: $this->getDefaultColumnTitle($rowValue->getColumnIndex()),
				);

				continue;
			}

			if ($this->isSkipEmptyColumns && empty($rowValue->getValue()))
			{
				continue;
			}

			$title = empty($rowValue->getValue())
				? $this->getDefaultColumnTitle($rowValue->getColumnIndex())
				: $rowValue->getValue();

			$fileHeaders[] = new Header(
				columnIndex: $rowValue->getColumnIndex(),
				title: $title,
			);
		}

		return $fileHeaders;
	}

	public function setIsFirstRowHasHeaders(bool $isFirstRowHasHeaders): self
	{
		$this->isFirstRowHasHeaders = $isFirstRowHasHeaders;

		return $this;
	}

	public function setIsSkipEmptyColumns(bool $isSkipEmptyColumns): self
	{
		$this->isSkipEmptyColumns = $isSkipEmptyColumns;

		return $this;
	}

	public function setDelimiter(Delimiter $delimiter): self
	{
		$this->file->setCsvControl($delimiter->getSymbol());

		return $this;
	}

	private function getDefaultColumnTitle(int $columnIndex): string
	{
		return Loc::getMessage('CRM_IMPORT_FILE_READER_CSV_DEFAULT_COLUMN_CAPTION', [
			'#ORDER#' => $columnIndex + 1,
		]);
	}
}
