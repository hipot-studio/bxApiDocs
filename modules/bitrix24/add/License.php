<?php

namespace Bitrix\Bitrix24;

/**
 * @method string getCode()
 */
class License
{
	public static function getCurrent(): self
	{
		return new self();
	}
}