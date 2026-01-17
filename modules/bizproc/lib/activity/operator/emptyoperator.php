<?php

namespace Bitrix\Bizproc\Activity\Operator;

use Bitrix\Bizproc\Activity\Enum\Operator;
use Bitrix\Main\Localization\Loc;

class EmptyOperator extends BaseOperator
{
	public static function getCode(): string
	{
		return Operator::Empty->value;
	}

	public static function getTitle(): string
	{
		return Loc::getMessage('BIZPROC_ACTIVITY_CONDITION_OPERATORS_EMPTY_OPERATOR_TITLE') ?? '';
	}

	protected function toBaseType(): void
	{}

	public function check(): bool
	{
		return \CBPHelper::isEmptyValue($this->toCheck);
	}
}
