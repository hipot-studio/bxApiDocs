<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Integration\Im;

final class AppChatOrigin
{
	private static int $depth = 0;

	public static function isServerInitiated(): bool
	{
		return self::$depth > 0;
	}

	public static function create(\Closure $creation): mixed
	{
		++self::$depth;
		try
		{
			return $creation();
		}
		finally
		{
			--self::$depth;
		}
	}
}
