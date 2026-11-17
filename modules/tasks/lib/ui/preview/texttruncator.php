<?php

declare(strict_types=1);

namespace Bitrix\Tasks\Ui\Preview;

final class TextTruncator
{
	public static function truncate(string $value, int $limit): string
	{
		if (mb_strlen($value) <= $limit)
		{
			return $value;
		}

		return mb_substr($value, 0, $limit) . '...';
	}
}
