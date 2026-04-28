<?php

namespace Bitrix\Crm\Import\Collection;

use Bitrix\Crm\Import\Dto\ImportItemsCollection\ImportItem;
use Bitrix\Crm\Item;
use Bitrix\Crm\Requisite\ImportHelper;
use Generator;

final class ImportItemCollection
{
	private array $importItems = [];
	private Generator $importItemIdGenerator;

	public function __construct()
	{
		$this->importItemIdGenerator = $this->getImportItemIdGenerator();
	}

	public function add(int $rowIndex, array $importItemValues, ?ImportHelper $requisiteImportHelper = null): self
	{
		$importItemId = $this->getImportItemId($importItemValues);

		if ($this->has($importItemId))
		{
			$this
				->get($importItemId)
				?->merge($rowIndex, $importItemValues)
			;

			return $this;
		}

		$this->importItems[$importItemId] = new ImportItem(
			$importItemId,
			$importItemValues,
			$rowIndex,
			$requisiteImportHelper,
		);

		return $this;
	}

	public function hasItem(array $importItemValues): bool
	{
		$importItemId = $importItemValues[Item::FIELD_NAME_ID] ?? null;

		return is_numeric($importItemId) && $this->has((int)$importItemId);
	}

	public function has(int $importItemId): bool
	{
		return $this->get($importItemId) !== null;
	}

	public function get(int $importItemId): ?ImportItem
	{
		return $this->importItems[$importItemId] ?? null;
	}

	public function count(): int
	{
		return count($this->importItems);
	}

	/**
	 * @return ImportItem[]
	 */
	public function getAll(): array
	{
		return $this->importItems;
	}

	private function getImportItemId(array $item): int
	{
		$itemId = $item[Item::FIELD_NAME_ID] ?? null;
		if (is_numeric($itemId) && (int)$itemId >= 0)
		{
			return (int)$itemId;
		}

		$this->importItemIdGenerator->next();

		return $this->importItemIdGenerator->current();
	}

	private function getImportItemIdGenerator(): Generator
	{
		$i = -1;

		while (true)
		{
			yield $i--;
		}
	}
}
