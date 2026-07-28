<?php

namespace Bitrix\BIConnector\Internal\Grid\UsageStat\Row\Assembler\Field;

use Bitrix\Main\Grid\Row\FieldAssembler;
use Bitrix\Main\Type;

final class TimestampFieldAssembler extends FieldAssembler
{
	protected function prepareColumn($value)
	{
		if ($value instanceof Type\DateTime)
		{
			return $value->toUserTime()->toString();
		}

		if ($value === null || $value === '')
		{
			return '';
		}

		return htmlspecialcharsbx((string)$value);
	}
}
