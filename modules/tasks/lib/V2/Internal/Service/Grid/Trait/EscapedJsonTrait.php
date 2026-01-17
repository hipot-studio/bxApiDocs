<?php

namespace Bitrix\Tasks\V2\Internal\Service\Grid\Trait;

use Bitrix\Main\Web\Json;
use Throwable;

trait EscapedJsonTrait
{
	protected function toEscapedJson(mixed $value): string
	{
		try
		{
			$value = Json::encode($value);
		}
		catch (Throwable)
		{
			return '';
		}

		return htmlspecialcharsbx($value);
	}
}
