<?php

namespace Bitrix\Crm\Multifield;

use Bitrix\Crm\Multifield\Type\Email;
use Bitrix\Crm\Multifield\Type\Im;
use Bitrix\Crm\Multifield\Type\Link;
use Bitrix\Crm\Multifield\Type\Phone;
use Bitrix\Crm\Multifield\Type\Web;

final class TypeRepository
{
	public static function isTypeDefined(mixed $typeId): bool
	{
		return self::getType($typeId) !== null;
	}

	public static function getTypeCaption(mixed $typeId): string
	{
		$type = self::getType($typeId);

		return (string)$type?->getCaption();
	}

	public static function getValueTypeCaption(mixed $typeId, mixed $valueType): string
	{
		return (string)self::getType($typeId)?->getValueTypeCaption((string)$valueType);
	}

	public static function getType(mixed $typeId): ?Type
	{
		foreach (self::getAll() as $type)
		{
			if ((string)$typeId === $type::ID)
			{
				return $type;
			}
		}

		return null;
	}

	/**
	 * @return Type[]
	 */
	private static function getAll(): array
	{
		return [
			new Email(),
			new Im(),
			new Link(),
			new Phone(),
			new Web(),
		];
	}

	private function __construct()
	{
	}
}
