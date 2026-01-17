<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\Action\Deadline;

class DeadlineFormatter
{
	public function format(?int $timestamp): string
	{
		if (!$timestamp)
		{
			return '';
		}

		$dateFormat = 'LONG_DATE_FORMAT';
		$timeFormat = 'SHORT_TIME_FORMAT';

		return "[TIMESTAMP={$timestamp} FORMAT={$dateFormat}], [TIMESTAMP={$timestamp} FORMAT={$timeFormat}]";
	}
}
