<?php

namespace Bitrix\Main\Security\W\Rules\Results;

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