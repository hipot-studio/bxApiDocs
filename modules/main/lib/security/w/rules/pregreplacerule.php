<?php

namespace Bitrix\Main\Security\W\Rules;

use Bitrix\Main\Text\StringHelper;
use Bitrix\Main\Security\W\Rules\Results\ModifyResult;

class PregReplaceRule extends PregRule
{
	public function evaluate($value)
	{
		if (!StringHelper::isStringable($value))
		{
			return new ModifyResult('');
		}

		$replaced = preg_replace($this->pattern, '', (string) $value);

		if ($replaced !== (string) $value)
		{
			return new ModifyResult($replaced);
		}

		return true;
	}
}