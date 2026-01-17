<?php

namespace Bitrix\Rest\V3\Structure;

use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Interaction\Request\Request;

abstract class Structure
{
	/**
	 * @todo try to avoid global cache map
	 */
	protected static array $dtos = [];

	public static function addDto(Dto $dto): void
	{
		if (!isset(static::$dtos[$dto::class]))
		{
			static::$dtos[$dto::class] = $dto;
		}
	}

	public static function getDto(string $class): ?Dto
	{
		return static::$dtos[$class] ?? null;
	}

	abstract public static function create(mixed $value, string $dtoClass, Request $request): self;
}
