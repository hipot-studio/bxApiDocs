<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Infrastructure\Grid\Shared\Row\Assembler\Field;

class ActivityDateFieldAssembler extends DateFieldAssembler
{
	protected function prepareColumn($value): string
	{
		if (!$value || !is_string($value))
		{
			return '';
		}

		$timestamp = MakeTimeStamp($value);
		if (!$timestamp)
		{
			$timestamp = strtotime($value);
		}

		if ($timestamp === false || $timestamp <= 0)
		{
			return '';
		}

		return \Bitrix\Socialnetwork\Helper\UI\DateTime::getDateValue(
			\Bitrix\Main\Type\DateTime::createFromTimestamp($timestamp),
		);
	}
}
