<?php

namespace Bitrix\Sign\Item\Document\Placeholder;

use Bitrix\Sign\Contract;
use Bitrix\Sign\Type\FieldType;

class Placeholder implements Contract\Item
{
	/**
	 * Types the fields list contract allows: only what the collector strategies and the regional
	 * document fields produce. Not FieldType::getAll() - it also holds types no source can reach.
	 */
	private const SUPPORTED_TYPES = [
		FieldType::FULL_NAME,
		FieldType::FIRST_NAME,
		FieldType::LAST_NAME,
		FieldType::PATRONYMIC,
		FieldType::POSITION,
		FieldType::SNILS,
		FieldType::INN,
		FieldType::PHONE,
		FieldType::EMAIL,
		FieldType::DATE,
		FieldType::ADDRESS,
		FieldType::STRING,
		FieldType::LIST,
		FieldType::INTEGER,
		FieldType::DOUBLE,
		FieldType::EXTERNAL_ID,
		FieldType::EXTERNAL_DATE,
	];

	public readonly ?string $type;

	public function __construct(
		public readonly string $name,
		public readonly string $value,
		?string $type = null,
	)
	{
		$this->type = self::isSupportedType($type) ? $type : null;
	}

	public static function isSupportedType(?string $type): bool
	{
		return $type !== null && in_array($type, self::SUPPORTED_TYPES, true);
	}
}
