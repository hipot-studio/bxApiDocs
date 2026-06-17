<?php

namespace Bitrix\Intranet\Public\Service\Invitation;

final class InviteContextManager
{
	private static ?InviteContext $context = null;

	public static function set(InviteContext $context): void
	{
		self::$context = $context;
	}

	public static function get(): ?InviteContext
	{
		return self::$context;
	}

	public static function clear(): void
	{
		self::$context = null;
	}
}
