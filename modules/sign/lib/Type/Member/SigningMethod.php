<?php

namespace Bitrix\Sign\Type\Member;

/**
 * Mirror of \Bitrix\SignSafe\Type\Member\SigningMethod, the method a member signs with as opposed to
 * the provider his company works with. Kept as constants because the portal side stores and forwards
 * the raw string; the values must stay identical to the signsafe enum.
 *
 * @todo sync with enum
 */
final class SigningMethod
{
	public const GOS_KEY = 'goskey';
	public const UKEP = 'ukep';

	/**
	 * @return array<self::*>
	 */
	public static function getAll(): array
	{
		return [
			self::GOS_KEY,
			self::UKEP,
		];
	}

	public static function isValid(string $method): bool
	{
		return in_array($method, self::getAll(), true);
	}
}
