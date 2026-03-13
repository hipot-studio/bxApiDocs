<?php

namespace Bitrix\Rest\V3\Schema;

use Bitrix\Main\Type\Date;
use Bitrix\Main\Type\DateTime;
use Bitrix\Rest\V3\Dto\Dto;

final class TypeAliasRegistry
{
	public static function toPublicType(mixed $returnType): ?string
	{
		if ($returnType === null)
		{
			return 'unknown';
		}

		if (is_string($returnType))
		{
			$normalizedType = strtolower(trim($returnType, "\\ \t\n\r\0\x0B"));

			if ($normalizedType === '')
			{
				return 'unknown';
			}

			if (in_array($normalizedType, ['int', 'integer', 'float', 'double', 'string', 'bool', 'boolean', 'array'], true))
			{
				return $normalizedType;
			}

			$normalizedFqcn = trim($returnType, '\\');

			if ($normalizedFqcn === DateTime::class)
			{
				return 'date-time';
			}

			if ($normalizedFqcn === Date::class)
			{
				return 'date';
			}

			return 'object';
		}

		if ($returnType instanceof Dto)
		{
			return $returnType->getTypeName();
		}

		if ($returnType instanceof \ReflectionClass)
		{
			if (is_subclass_of($returnType->getName(), Dto::class))
			{
				try {
					$dto = $returnType->getName()::create();
				}
				catch (\Exception)
				{
					return 'object';
				}

				return $dto->getTypeName();
			}

			return 'object';
		}

		if (is_int($returnType))
		{
			return 'int';
		}

		if (is_float($returnType))
		{
			return 'float';
		}

		if (is_bool($returnType))
		{
			return 'bool';
		}

		if (is_array($returnType))
		{
			return 'array';
		}

		return 'object';
	}
}