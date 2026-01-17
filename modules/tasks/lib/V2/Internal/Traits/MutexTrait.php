<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Traits;

trait MutexTrait
{
	private static bool $mutex = false;

	private static function locked(): bool
	{
		return self::$mutex;
	}

	private static function lock(): void
	{
		self::$mutex = true;
	}
	
	private static function unlock(): void
	{
		self::$mutex = false;
	}
}
