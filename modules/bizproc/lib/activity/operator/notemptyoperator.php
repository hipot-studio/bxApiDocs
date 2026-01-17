<?php

namespace Bitrix\Bizproc\Activity\Operator;

use Bitrix\Bizproc\Activity\Enum\Operator;
use Bitrix\Main\Localization\Loc;

class NotEmptyOperator extends EmptyOperator
{
	public static function getCode(): string
	{
		return Operator::NotEmpty->value;
	}

	public static function getTitle(): string
	{
		return Loc::getMessage('BIZPROC_ACTIVITY_CONDITION_OPERATORS_NOT_EMPTY_OPERATOR_TITLE') ?? '';
	}

	public function check(): bool
	{
		return !parent::check();
	}
}
