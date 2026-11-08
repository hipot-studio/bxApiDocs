<?php

declare(strict_types=1);

namespace Bitrix\Rest\V3\Dto;

final class TypeHelper
{
	private const RESERVED_TYPE_NAMES = [
		'array' => true,
		'bool' => true,
		'callable' => true,
		'false' => true,
		'float' => true,
		'int' => true,
		'iterable' => true,
		'mixed' => true,
		'never' => true,
		'null' => true,
		'object' => true,
		'parent' => true,
		'self' => true,
		'static' => true,
		'string' => true,
		'true' => true,
		'void' => true,
	];

	private const CLASS_NAME_PATTERN = '/^\\\\?[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*'
		. '(?:\\\\[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*)*\z/'
	;

	/** @var array<string, bool> answers given for an already declared class: the parent will not change */
	private static array $settled = [];

	/** @var array<string, bool> answers given before the class existed */
	private static array $pending = [];

	public static function isDto(?string $type): bool
	{
		return self::isSubclassOf($type, Dto::class);
	}

	public static function isBackedEnum(?string $type): bool
	{
		return self::isSubclassOf($type, \BackedEnum::class);
	}

	public static function classExists(?string $type): bool
	{
		if ($type === null || !self::canBeClassName($type))
		{
			return false;
		}

		return class_exists($type);
	}

	public static function isSubclassOf(?string $type, string $parentClass): bool
	{
		// a type name from reflection comes lower case and without the leading slash
		if ($type === null || isset(self::RESERVED_TYPE_NAMES[$type]))
		{
			return false;
		}

		$key = $type . "\0" . $parentClass;

		if (isset(self::$settled[$key]))
		{
			return self::$settled[$key];
		}

		if (isset(self::$pending[$key]))
		{
			// the answer was given before the class existed: Dto\Generator can declare it later in the same process
			if (!class_exists($type, false))
			{
				return self::$pending[$key];
			}

			unset(self::$pending[$key]);
		}

		if (!self::canBeClassName($type))
		{
			return false;
		}

		// autoload stays enabled on purpose: a real nested DTO class is regularly not loaded yet here
		$answer = is_subclass_of($type, $parentClass);

		if (class_exists($type, false))
		{
			self::$settled[$key] = $answer;
		}
		else
		{
			self::$pending[$key] = $answer;
		}

		return $answer;
	}

	private static function canBeClassName(string $type): bool
	{
		if (isset(self::RESERVED_TYPE_NAMES[strtolower(ltrim($type, '\\'))]))
		{
			return false;
		}

		return preg_match(self::CLASS_NAME_PATTERN, $type) === 1;
	}
}
