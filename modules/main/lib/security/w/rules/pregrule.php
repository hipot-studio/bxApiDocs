<?php

namespace Bitrix\Main\Security\W\Rules;

abstract class PregRule extends Rule
{
	protected $pattern;

	public function __construct($path, $context, $keys, $process, $encoding, $pattern)
	{
		parent::__construct($path, $context, $keys, $process, $encoding);

		$this->pattern = $pattern;
	}
}