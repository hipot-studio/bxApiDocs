<?php

namespace Bitrix\Main\Security\W\Rules\Results;

use Bitrix\Main\Security\W\Rules\Results\RuleResult;

class ModifyResult extends RuleResult
{
	protected $cleanValue;

	public function __construct($cleanValue)
	{
		$this->cleanValue = $cleanValue;
	}

	/**
	 * @return mixed
	 */
	public function getCleanValue(): mixed
	{
		return $this->cleanValue;
	}
}