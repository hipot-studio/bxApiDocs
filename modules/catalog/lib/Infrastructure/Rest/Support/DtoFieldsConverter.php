<?php

declare(strict_types=1);

namespace Bitrix\Catalog\Infrastructure\Rest\Support;

use Bitrix\Main\Text\StringHelper;
use Bitrix\Rest\V3\Dto\Dto;
use ReflectionProperty;

final class DtoFieldsConverter
{
	/**
	 * Converts initialized DTO properties to UPPER_SNAKE ORM/domain field array.
	 *
	 * @return array<string, mixed>
	 */
	public static function toOrmFields(Dto $dto, bool $skipId = true): array
	{
		$fields = [];

		foreach (get_object_vars($dto) as $property => $value)
		{
			if ($skipId && $property === 'id')
			{
				continue;
			}

			$reflection = new ReflectionProperty($dto, $property);
			if (!$reflection->isInitialized($dto))
			{
				continue;
			}

			$ormField = strtoupper(StringHelper::camel2snake($property));
			$fields[$ormField] = $value;
		}

		return $fields;
	}
}
