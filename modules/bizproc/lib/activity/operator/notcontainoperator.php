<?php

namespace Bitrix\Bizproc\Activity\Operator;

use Bitrix\Bizproc\Activity\Enum\Operator;
use Bitrix\Main\Localization\Loc;

class NotContainOperator extends ContainOperator
{
	public static function getCode(): string
	{
		return Operator::NotContain->value;
	}

	public static function getTitle(): string
	{
		return Loc::getMessage('BIZPROC_ACTIVITY_CONDITION_OPERATORS_NOT_CONTAIN_OPERATOR_TITLE') ?? '';
	}

	public function check(): bool
	{
		return !parent::check();
	}
}
