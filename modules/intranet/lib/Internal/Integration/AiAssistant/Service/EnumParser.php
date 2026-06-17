<?php

namespace Bitrix\Intranet\Internal\Integration\AiAssistant\Service;

use BackedEnum;
use Bitrix\AiAssistant\Exceptions\McpException;

class EnumParser
{
	public static function parseNullableStringEnum(
		mixed $value,
		string $valueName,
		string $enumClass,
	): ?BackedEnum
	{
		$value = StringParser::parse($value, $valueName);

		if ($value === null)
		{
			return null;
		}

		if (!class_exists($enumClass))
		{
			throw new McpException("Enum class \"{$enumClass}\" does not exist.");
		}

		if (!is_subclass_of($enumClass, BackedEnum::class))
		{
			throw new McpException("Class \"{$enumClass}\" must be a backed enum.");
		}

		$enum = $enumClass::tryFrom($value);
		if ($enum === null)
		{
			$availableValues = implode(
				', ',
				array_map(
					static fn(BackedEnum $case) => (string)$case->value,
					$enumClass::cases(),
				),
			);

			throw new McpException(
				"Parameter \"{$valueName}\" must be one of: {$availableValues}.",
			);
		}

		return $enum;
	}
}
