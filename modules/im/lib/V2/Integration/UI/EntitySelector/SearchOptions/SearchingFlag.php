<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\UI\EntitySelector\SearchOptions;

enum SearchingFlag: string
{
	case Chats = 'chats';
	case Users = 'users';
	case Bots = 'bots';

	public static function isValid(string $flag): bool
	{
		return self::tryFrom($flag) !== null;
	}

	/**
	 * @param string[] $array
	 * @return SearchingFlag[]
	 */
	public static function fromStringArray(array $array): array
	{
		$result = [];
		foreach ($array as $flag)
		{
			if (self::isValid($flag))
			{
				$result[] = self::tryFrom($flag);
			}
		}

		return $result;
	}
}
