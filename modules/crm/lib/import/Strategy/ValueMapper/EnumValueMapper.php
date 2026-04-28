<?php

namespace Bitrix\Crm\Import\Strategy\ValueMapper;

use Bitrix\Crm\Import\Dto\Entity\FieldBindings;
use Bitrix\Crm\Import\Result\FieldProcessResult;

final class EnumValueMapper
{
	public function __construct(
		private readonly string $fieldId,
		private readonly array $items,
		private readonly bool $isUseFirstItemIfNotFound = false,
	)
	{
	}

	public function process(array &$importItemFields, FieldBindings $fieldBindings, array $row): FieldProcessResult
	{
		$columnIndex = $fieldBindings->getColumnIndexByFieldId($this->fieldId);
		if ($columnIndex === null)
		{
			return FieldProcessResult::skip();
		}

		$item = $row[$columnIndex] ?? null;
		if (empty($item))
		{
			return FieldProcessResult::skip();
		}

		if (isset($this->items[$item]))
		{
			$importItemFields[$this->fieldId] = $item;

			return FieldProcessResult::success();
		}

		$searchResult = array_search($item, $this->items, false);
		if ($searchResult !== false)
		{
			$importItemFields[$this->fieldId] = $searchResult;

			return FieldProcessResult::success();
		}

		if (!$this->isUseFirstItemIfNotFound)
		{
			FieldProcessResult::skip();
		}

		$importItemFields[$this->fieldId] = array_key_first($this->items);

		return FieldProcessResult::success();
	}
}
