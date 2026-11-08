<?php

namespace Bitrix\Bizproc\Workflow\Type\Entity;

use Bitrix\Bizproc\BaseType\Value;
use Bitrix\Main;

trait PropertyValueSerializationTrait
{
	public static function getPropertyValueSaveModifiers(): array
	{
		return [
			static fn($value) => serialize($value),
		];
	}

	public static function getPropertyValueFetchModifiers(): array
	{
		return [
			static fn($value) => $value === null
				? null
				: unserialize(
					(string)$value,
					[
						'allowed_classes' => [
							Value\Date::class,
							Value\DateTime::class,
							Value\Time::class,
							Main\Type\Date::class,
							Main\Type\DateTime::class,
							\DateTime::class,
							\DateTimeZone::class,
						],
					],
				)
			,
		];
	}
}
