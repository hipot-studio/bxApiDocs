<?php

namespace Bitrix\Crm\Import\File\Reader;

use Bitrix\Crm\Import\Contract\File\ReaderInterface;
use Bitrix\Crm\Import\Enum\VCard\Column;
use Bitrix\Crm\Import\File\Header;
use Bitrix\Crm\Import\File\Row;
use Bitrix\Crm\Import\File\RowValue;
use Generator;
use SplFileObject;

final class VCardReader implements ReaderInterface
{
	private readonly SplFileObject $file;
	private int $line = 0;

	public function __construct(
		private readonly string $filepath,
	)
	{
		$this->file = new SplFileObject($this->filepath);
	}

	public function read(?int $limit = null): Generator
	{
		$this->file->rewind();
		$currentVCardIndex = 0;
		$count = 0;

		$currentCard = [];
		$inVCard = false;

		while (!$this->file->eof())
		{
			if ($limit !== null && $count >= $limit)
			{
				break;
			}

			$line = rtrim($this->file->fgets());
			if (empty($line))
			{
				continue;
			}

			if (str_starts_with($line, 'BEGIN:VCARD'))
			{
				$inVCard = true;
				$currentCard = [];

				continue;
			}

			if (str_starts_with($line, 'END:VCARD'))
			{
				if ($inVCard && !empty($currentCard))
				{
					if ($currentVCardIndex === $this->line)
					{
						yield $this->parse($this->line++, $currentCard);
						$count++;
					}

					$currentVCardIndex++;
				}

				$inVCard = false;

				continue;
			}

			if ($inVCard)
			{
				$currentCard[] = $line;
			}
		}
	}

	public function parse(int $columnIndex, array $rawVCard): Row
	{
		$row = new Row($columnIndex);
		$itemGroups = [];

		$processedLines = [];
		$currentLine = '';

		foreach ($rawVCard as $line)
		{
			if (str_starts_with($line, ' ') || str_starts_with($line, "\t"))
			{
				$currentLine .= ltrim($line);

				continue;
			}

			if ($currentLine !== '')
			{
				$processedLines[] = $currentLine;
			}

			$currentLine = $line;
		}

		if ($currentLine !== '')
		{
			$processedLines[] = $currentLine;
		}

		foreach ($processedLines as $line)
		{
			[$preferences] = explode(':', $line, 2);

			$preferences = explode(';', $preferences);
			$columnName = $preferences[0];

			$itemGroup = null;
			if (preg_match('/^item(\d+)\.(.+)$/', $columnName, $matches))
			{
				[, $itemGroup, $columnName] = $matches;
				$itemGroup = (int)$itemGroup;
			}

			$column = Column::tryFromColumnName($columnName);
			if ($column === null)
			{
				continue;
			}

			if ($itemGroup !== null)
			{
				$itemGroups[$itemGroup][$column->index()] ??= [];
				$itemGroups[$itemGroup][$column->index()][] = $line;

				continue;
			}

			$rowValue = $row->getValue($column->index());
			if ($rowValue === null)
			{
				$rowValue = new RowValue($column->index(), [
					[$line],
				]);

				$row->setValue($rowValue);

				continue;
			}

			$value = $rowValue->getValue();
			$value[] = [$line];

			$rowValue->setValue($value);
		}

		foreach ($itemGroups as $groupData)
		{
			foreach ($groupData as $column => $values)
			{
				$rowValue = $row->getValue($column);
				if ($rowValue === null)
				{
					$rowValue = new RowValue($column, [$values]);
					$row->setValue($rowValue);

					continue;
				}

				$value = $rowValue->getValue();
				$value[] = $values;

				$rowValue->setValue($value);
			}
		}

		return $row;
	}

	public function readRow(int $rowIndex): ?Row
	{
		$this->setCurrentLine($rowIndex);

		foreach ($this->read() as $row)
		{
			if ($row->getIndex() === $rowIndex)
			{
				return $row;
			}
		}

		return null;
	}

	public function setCurrentLine(int $line): ReaderInterface
	{
		$this->line = $line;

		return $this;
	}

	public function getCurrentLine(): int
	{
		return $this->line;
	}

	public function getPosition(): int
	{
		return $this->file->ftell();
	}

	public function getFilesize(): int
	{
		return filesize($this->filepath);
	}

	public function isEndOfFile(): bool
	{
		return $this->file->eof();
	}

	public function getHeaders(): array
	{
		$headers = [];

		foreach (Column::cases() as $column)
		{
			$headers[] = new Header($column->index(), $column->getColumnName());
		}

		return $headers;
	}

	public function rewind(): self
	{
		$this->setCurrentLine(0);

		return $this;
	}
}
