<?php

namespace Bitrix\Sign\Type\Document;

final class SchemeType
{
	public const DEFAULT = 'default';
	public const ORDER = 'order';

	public const DEFAULT_ID = 0;
	public const ORDER_ID = 1;

	public const SCHEME_TYPE_TO_ID_MAP = [
		self::DEFAULT => self::DEFAULT_ID,
		self::ORDER => self::ORDER_ID,
	];

	/**
	 * @return array<self::*>
	 */
	public static function getAll(): array
	{
		return [
			self::DEFAULT,
			self::ORDER,
		];
	}

	public static function isValid(string $scheme): bool
	{
		return in_array($scheme, self::getAll(), true);
	}
}