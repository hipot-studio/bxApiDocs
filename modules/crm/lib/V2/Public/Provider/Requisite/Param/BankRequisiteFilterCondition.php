<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Provider\Requisite\Param;

final class BankRequisiteFilterCondition
{
	public function __construct(
		public readonly string $field,
		public readonly BankRequisiteFilterOperator $operator,
		public readonly mixed $value,
	)
	{
	}
}
