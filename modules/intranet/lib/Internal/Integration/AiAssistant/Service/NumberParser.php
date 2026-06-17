<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\AiAssistant\Service;

use Bitrix\AiAssistant\Exceptions\McpException;

class NumberParser
{
	public static function parseLimitedInt(
		mixed $value,
		string $valueName,
		?int $defaultValue,
		int $min = 0,
		int $max = PHP_INT_MAX,
	): ?int
	{
		if ($value === null)
		{
			return $defaultValue;
		}

		if (!is_int($value))
		{
			throw new McpException("Parameter \"{$valueName}\" must be an integer.");
		}

		if ($value < $min || ($max !== null && $value > $max))
		{
			throw new McpException(
				"Parameter \"{$valueName}\" must be between {$min} and {$max}.",
			);
		}

		return $value;
	}
}
