<?php

namespace Bitrix\Main\Security\W\Rules\Results;

use Bitrix\Main\Security\W\Rules\Results\RuleResult;

class CheckResult extends RuleResult
{
	protected $success;

	protected $action;

	public function __construct($success, $action)
	{
		$this->success = $success;
		$this->action = $action;
	}

	public function isSuccess()
	{
		return $this->success;
	}

	/**
	 * @return mixed
	 */
	public function getAction()
	{
		return $this->action;
	}
}