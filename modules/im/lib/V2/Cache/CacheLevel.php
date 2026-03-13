<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Cache;

enum CacheLevel
{
	case Static;
	case Persistent;
	case All;

	public function isSubsetOf(CacheLevel $level): bool
	{
		if ($level === self::All)
		{
			return true;
		}

		return $level === $this;
	}
}
