<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Provider\Requisite\Param;

final class BankRequisiteFilter
{
	/** @var array<BankRequisiteFilterCondition|self> */
	private array $conditions = [];

	public function __construct(
		private readonly bool $or = false,
		private readonly bool $negative = false,
	)
	{
	}

	public function where(
		string $field,
		BankRequisiteFilterOperator $operator,
		mixed $value,
	): self
	{
		$this->conditions[] = new BankRequisiteFilterCondition($field, $operator, $value);

		return $this;
	}

	public function add(self $filter): self
	{
		$this->conditions[] = $filter;

		return $this;
	}

	/** @return array<BankRequisiteFilterCondition|self> */
	public function getConditions(): array
	{
		return $this->conditions;
	}

	public function isOr(): bool
	{
		return $this->or;
	}

	public function isNegative(): bool
	{
		return $this->negative;
	}
}
