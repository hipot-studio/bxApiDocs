<?php

namespace Bitrix\Crm\Import\Strategy\ValueMapper;

use Bitrix\Crm\Import\Dto\Entity\FieldBindings;
use Bitrix\Crm\Import\Result\FieldProcessResult;
use CCrmUserType;

final class UserTypeValueMapper
{
	public function __construct(
		private readonly string $fieldId,
		private readonly CCrmUserType $userType,
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
		if (empty($value))
		{
			return FieldProcessResult::skip();
		}

		$importItemFields[$this->fieldId] = $this->userType->Internalize($this->fieldId, $value);

		return FieldProcessResult::success();
	}
}
