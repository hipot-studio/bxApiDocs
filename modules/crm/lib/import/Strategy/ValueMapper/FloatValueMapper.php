<?php

namespace Bitrix\Crm\Import\Strategy\ValueMapper;

use Bitrix\Crm\Import\Dto\Entity\FieldBindings;
use Bitrix\Crm\Import\Result\FieldProcessResult;

final class FloatValueMapper
{
	public function __construct(
		private readonly string $fieldId,
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

		$importItemFields[$this->fieldId] = (float)$value;

		return FieldProcessResult::success();
	}
}
