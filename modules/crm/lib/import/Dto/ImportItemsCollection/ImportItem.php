<?php

namespace Bitrix\Crm\Import\Dto\ImportItemsCollection;

use Bitrix\Crm\Item;
use Bitrix\Crm\Requisite\ImportHelper;

final class ImportItem
{
	private array $rowIndexes = [];

	private const MERGE_FIELDS_WHITELIST = [
		Item::FIELD_NAME_PRODUCTS,
	];

	public function __construct(
		public readonly int $id,
		public array $values,
		int $rowIndex,
		private readonly ?ImportHelper $requisiteImportHelper = null,
	)
	{
		$this->rowIndexes[] = $rowIndex;
	}

	public function merge(int $rowIndex, array $values): self
	{
		foreach (self::MERGE_FIELDS_WHITELIST as $fieldName)
		{
			$fieldValue = $values[$fieldName] ?? null;

			$isNeedAppendNewValues = isset($this->values[$fieldName])
				&& is_array($fieldValue)
				&& is_array($this->values[$fieldName])
			;

			if ($isNeedAppendNewValues)
			{
				$this->values[$fieldName] = array_merge($this->values[$fieldName], $fieldValue);
			}
		}

		$this->rowIndexes[] = $rowIndex;

		return $this;
	}

	/**
	 * @return int[]
	 */
	public function getRowIndexes(): array
	{
		$rowIndexes = $this->rowIndexes;

		if ($this->requisiteImportHelper !== null)
		{
			$rowIndexes = array_merge($rowIndexes, $this->requisiteImportHelper->getRowIndexes());
		}

		return array_unique($rowIndexes);
	}

	public function getRequisiteImportHelper(): ?ImportHelper
	{
		return $this->requisiteImportHelper;
	}
}
