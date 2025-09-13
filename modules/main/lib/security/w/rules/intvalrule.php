<?php

namespace Bitrix\Main\Security\W\Rules;

use Bitrix\Main\Text\StringHelper;
use Bitrix\Main\Security\W\Rules\Results\ModifyResult;
use Bitrix\Main\Security\W\Rules\Results\RuleResult;

class IntvalRule extends Rule
{
	public function evaluate($value): bool | RuleResult
	{
		if (!StringHelper::isStringable($value))
		{
			return new ModifyResult(0);
		}

		if (!preg_match('/^\d+$/', (string) $value))
		{
			return new ModifyResult(intval((string) $value));
		}

		return true;
	}
}