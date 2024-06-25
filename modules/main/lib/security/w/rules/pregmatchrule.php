<?php

namespace Bitrix\Main\Security\W\Rules;

use Bitrix\Main\Text\StringHelper;
use Bitrix\Main\Security\W\Rules\Results\CheckResult;

class PregMatchRule extends PregRule
{
	protected $action;

	public function __construct($path, $context, $keys, $process, $encoding, $pattern, $action)
	{
		parent::__construct($path, $context, $keys, $process, $encoding, $pattern);

		$this->action = $action;
	}

	public function evaluate($value)
	{
		$failure = !StringHelper::isStringable($value) || preg_match($this->pattern, $value);

		if ($failure)
		{
			return new CheckResult(
				false,
				$this->action
			);
		}

		return true;
	}

	/**
	 * @return mixed
	 */
	public function getAction()
	{
		return $this->action;
	}
}