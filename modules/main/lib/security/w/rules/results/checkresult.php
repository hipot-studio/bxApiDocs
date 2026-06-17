<?php

namespace Bitrix\Main\Security\W\Rules\Results;

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