<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\AiAssistant\Service;

use Bitrix\AiAssistant\Exceptions\McpException;

class ArrayParser
{
	public static function parse(mixed $value, string $valueName): array
	{
		if ($value === null)
		{
			return [];
		}

		if (!is_array($value))
		{
			throw new McpException("Parameter \"{$valueName}\" must be an array.");
		}

		return $value;
	}

}
