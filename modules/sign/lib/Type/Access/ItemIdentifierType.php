<?php

namespace Bitrix\Sign\Type\Access;

/**
 * Tells the access filter which entity the request key identifies when it is not the access
 * item itself. Without it the filter would have to guess by the value format, and a member uid
 * made of digits only (legacy md5 hash) would be read as a primary key.
 */
final class ItemIdentifierType
{
	public const MEMBER_UID = 'memberUid';

	/**
	 * @return array<self::*>
	 */
	public static function getAll(): array
	{
		return [
			self::MEMBER_UID,
		];
	}
}
