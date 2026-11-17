<?php

namespace Bitrix\Disk\Internal\Integration\B24Boards;

final class MenuCacheInvalidator
{
	public static function invalidate(): void
	{
		\CBitrixComponent::clearComponentCache('bitrix:menu');
		$GLOBALS['CACHE_MANAGER']->CleanDir('menu');
		$GLOBALS['CACHE_MANAGER']->ClearByTag('bitrix24_left_menu');
	}
}
