<?php

namespace Bitrix\Crm\Import\Builder;

use Bitrix\Crm\Import\Collection\FieldCollection;
use Bitrix\Crm\Import\Contract\File\WriterInterface;
use Bitrix\Crm\Import\File\Row;
use Bitrix\Crm\Import\File\RowValue;
use Bitrix\Crm\Import\File\Writer\CSVWriter;
use CTempFile;

final class CsvExampleFileBuilder
{
	private array $exampleRows = [];
	private FieldCollection $fields;
	private string $filename;

	public function setFields(FieldCollection $fields): self
	{
		$this->fields = $fields;

		return $this;
	}

	public function setExampleRows(array $data): self
	{
		$this->exampleRows = $data;

		return $this;
	}

	public function setFilename(string $filename): self
	{
		$this->filename = $filename;

		return $this;
	}

	public function build(): string
	{
		$filename = CTempFile::GetFileName($this->filename);
		CheckDirPath($filename);

		$writer = new CSVWriter($filename);

		$this->writeHeaders($writer);
		$this->writeRows($writer);

		return $filename;
	}

	private function writeHeaders(WriterInterface $writer): void
	{
		$columnIndex = 0;

		$row = new Row(rowIndex: 0);
		foreach ($this->fields->getAll() as $field)
		{
			$row->setValue(
				new RowValue(
					columnIndex: $columnIndex++,
					value: $field->getCaption(),
				),
			);
		}

		$writer->write($row);
	}

	private function writeRows(WriterInterface $writer): void
	{
		$rowIndex = 1;

		foreach ($this->exampleRows as $data)
		{
			$columnIndex = 0;
			$row = new Row(rowIndex: $rowIndex++);

			foreach ($this->fields->getAll() as $field)
			{
				$value = $data[$field->getId()] ?? '';

				$row->setValue(
					new RowValue(
						columnIndex: $columnIndex++,
						value: $value,
					),
				);
			}

			$writer->write($row);
		}
	}
}
