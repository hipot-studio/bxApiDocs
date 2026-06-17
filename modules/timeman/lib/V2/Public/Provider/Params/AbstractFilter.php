<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Public\Provider\Params;

use Bitrix\Main\Provider\Params\FilterInterface;

abstract class AbstractFilter implements FilterInterface
{
	public function getAllowedFields(): array
	{
		$enumClass = static::fieldsEnumClass();

		return array_map(
			static fn($field) => $field->value,
			$enumClass::allowedForFilterList(),
		);
	}

	/**
	 * @return class-string
	 */
	abstract protected static function fieldsEnumClass(): string;
}
