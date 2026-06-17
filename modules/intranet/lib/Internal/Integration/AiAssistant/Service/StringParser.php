<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\AiAssistant\Service;

use Bitrix\AiAssistant\Exceptions\McpException;

class StringParser
{
	public static function parse(mixed $value, string $valueName, bool $required = false): ?string
	{
		if ($value === null)
		{
			return
				$required
				? throw new McpException("Parameter \"{$valueName}\" must not be null.")
				: null
			;
		}

		if (!is_string($value))
		{
			throw new McpException("Parameter \"{$valueName}\" must be a string.");
		}

		$value = trim($value);

		if ($value === '')
		{
			return
				$required
				? throw new McpException("Parameter \"{$valueName}\" must not be an empty string.")
				: null
			;
		}

		return $value;
	}
}
