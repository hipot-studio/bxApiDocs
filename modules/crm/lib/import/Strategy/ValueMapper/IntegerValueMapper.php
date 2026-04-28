<?php

namespace Bitrix\Crm\Import\Strategy\ValueMapper;

use Bitrix\Crm\Import\Dto\Entity\FieldBindings;
use Bitrix\Crm\Import\Result\FieldProcessResult;

final class IntegerValueMapper
{
	public function __construct(
		private readonly string $fieldId,
		private readonly ?int $min = null,
		private readonly ?int $max = null,
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

		$value = $row[$columnIndex] ?? null;
		if (empty($value) || !is_numeric($value))
		{
			return FieldProcessResult::skip();
		}

		$number = (int)$value;

		if ($this->min !== null && $number < $this->min)
		{
			return FieldProcessResult::skip();
		}

		if ($this->max !== null && $number > $this->max)
		{
			return FieldProcessResult::skip();
		}

		$importItemFields[$this->fieldId] = $number;

		return FieldProcessResult::success();
	}
}
