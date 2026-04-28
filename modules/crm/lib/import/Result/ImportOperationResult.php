<?php

namespace Bitrix\Crm\Import\Result;

use Bitrix\Crm\Import\Result\Error\RowsErrorPack;
use Bitrix\Crm\Result;

final class ImportOperationResult extends Result
{
	private int $progressedBytes = 0;
	private int $successImportCount = 0;
	private int $currentLine = 0;
	private bool $isFinished = false;
	private array $duplicateRowIndexes = [];
	private int $duplicateImportCount = 0;
	private array $errorPackList = [];

	public function incrementProgressedBytes(int $bytes): self
	{
		$this->progressedBytes += $bytes;

		return $this;
	}

	public function getProgressedBytes(): int
	{
		return $this->progressedBytes;
	}

	public function getSuccessImportCount(): int
	{
		return $this->successImportCount;
	}

	public function incrementSuccessImportCount(int $count = 1): self
	{
		$this->successImportCount += $count;

		return $this;
	}

	public function getFailImportCount(): int
	{
		return count($this->errorPackList);
	}

	public function getCurrentLine(): int
	{
		return $this->currentLine;
	}

	public function setCurrentLine(int $currentLine): self
	{
		$this->currentLine = $currentLine;

		return $this;
	}

	public function isFinished(): bool
	{
		return $this->isFinished;
	}

	public function setIsFinished(bool $isFinish): self
	{
		$this->isFinished = $isFinish;

		return $this;
	}

	public function addDuplicateRowIndexes(array $indexes): self
	{
		foreach ($indexes as $index)
		{
			if (in_array($index, $this->duplicateRowIndexes, true))
			{
				continue;
			}

			$this->duplicateRowIndexes[] = $index;
		}

		return $this;
	}

	public function getDuplicateRowIndexes(): array
	{
		return $this->duplicateRowIndexes;
	}

	public function incrementDuplicateImportCount(int $count = 1): self
	{
		$this->duplicateImportCount += $count;

		return $this;
	}

	public function getDuplicateImportCount(): int
	{
		return $this->duplicateImportCount;
	}

	public function hasDuplicates(): bool
	{
		return $this->getDuplicateImportCount() > 0;
	}

	public function addErrorPack(RowsErrorPack $pack): self
	{
		$this->isSuccess = false;
		$this->errorPackList[] = $pack;

		return $this;
	}

	/**
	 * @return RowsErrorPack[]
	 */
	public function getErrorPackList(): array
	{
		return $this->errorPackList;
	}
}
