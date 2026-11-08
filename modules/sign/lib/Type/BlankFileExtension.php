<?php

namespace Bitrix\Sign\Type;

final class BlankFileExtension
{
	/**
	 * @var list<string>
	 */
	public const ALLOWED = ['jpg', 'jpeg', 'png', 'pdf', 'doc', 'docx', 'rtf', 'odt'];

	public static function isAllowed(string $extension): bool
	{
		return in_array(mb_strtolower($extension), self::ALLOWED, true);
	}
}
