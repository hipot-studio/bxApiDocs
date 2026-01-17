<?php

namespace Bitrix\Bizproc\Activity\Trigger;

use Bitrix\Bizproc\Activity\Enum\Joiner;
use Bitrix\Bizproc\Activity\Enum\Operator;
use Bitrix\Bizproc\BaseType\StringType;
use Bitrix\Bizproc\FieldType;

class TriggerRule
{
	public readonly string $parameterName;
	public readonly mixed $value;
	public readonly Operator $operator;
	public readonly Joiner $joiner;
	public readonly FieldType $fieldType;

	private bool $result = true;

	public function __construct(
		string $parameterName,
		mixed $value,
		Operator $operator = Operator::Equal,
		Joiner $joiner = Joiner::And,
		FieldType $fieldType = new FieldType([], [], StringType::class),
	)
	{
		$this->parameterName = $parameterName;
		$this->value = $value;
		$this->operator = $operator;
		$this->joiner = $joiner;
		$this->fieldType = $fieldType;
	}

	public function setResult(bool $result = true): static
	{
		$this->result = $result;

		return $this;
	}

	public function getResult(): bool
	{
		return $this->result;
	}
}
