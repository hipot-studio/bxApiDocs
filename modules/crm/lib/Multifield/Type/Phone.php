<?php

namespace Bitrix\Crm\Multifield\Type;

use Bitrix\Crm\Multifield\Type;

final class Phone extends Type
{
	public const ID = 'PHONE';

	public const VALUE_TYPE_WORK = 'WORK';
	public const VALUE_TYPE_MOBILE = 'MOBILE';
	public const VALUE_TYPE_FAX = 'FAX';
	public const VALUE_TYPE_HOME = 'HOME';
	public const VALUE_TYPE_PAGER = 'PAGER';
	public const VALUE_TYPE_MAILING = 'MAILING';
	public const VALUE_TYPE_OTHER = 'OTHER';

	public function formatValue(string $value): string
	{
		return \Bitrix\Main\PhoneNumber\Parser::getInstance()->parse($value)->format();
	}

	public function getValueTypes(): array
	{
		return [
			self::VALUE_TYPE_WORK,
			self::VALUE_TYPE_MOBILE,
			self::VALUE_TYPE_FAX,
			self::VALUE_TYPE_HOME,
			self::VALUE_TYPE_PAGER,
			self::VALUE_TYPE_MAILING,
			self::VALUE_TYPE_OTHER,
		];
	}
}
