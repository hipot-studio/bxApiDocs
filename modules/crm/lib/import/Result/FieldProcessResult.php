<?php

namespace Bitrix\Crm\Import\Result;

use Bitrix\Crm\Result;

final class FieldProcessResult extends Result
{
	public static function skip(): self
	{
		return self::success();
	}
}
