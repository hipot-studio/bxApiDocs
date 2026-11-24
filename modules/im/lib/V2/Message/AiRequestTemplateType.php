<?php

namespace Bitrix\Im\V2\Message;

/**
 * Вид записи библиотеки сценариев: редакционный набор или запись человека.
 *
 * Мессенджеру эти значения нужны ровно для одного — не пропустить незнакомое: всё остальное про
 * запись знает сторона AI.
 */
enum AiRequestTemplateType: string
{
	case System = 'system';
	case Personal = 'personal';

	/**
	 * @return string[]
	 */
	public static function values(): array
	{
		return array_map(static fn (self $type): string => $type->value, self::cases());
	}
}
