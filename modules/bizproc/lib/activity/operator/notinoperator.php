<?php

namespace Bitrix\Bizproc\Activity\Operator;

use Bitrix\Bizproc\Activity\Enum\Operator;
use Bitrix\Main\Localization\Loc;

class NotInOperator extends InOperator
{
	public static function getCode(): string
	{
		return Operator::NotIn->value;
	}

	public static function getTitle(): string
	{
		return Loc::getMessage('BIZPROC_ACTIVITY_CONDITION_OPERATORS_NOT_IN_OPERATOR_TITLE') ?? '';
	}

	public function check(): bool
	{
		return !parent::check();
	}
}
