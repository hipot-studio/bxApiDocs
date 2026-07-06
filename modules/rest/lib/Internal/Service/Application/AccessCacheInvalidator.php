<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Service\Application;

use Bitrix\Rest\PlacementTable;

class AccessCacheInvalidator
{
	public static function clear(): void
	{
		PlacementTable::clearHandlerCache();

		if (defined('BX_COMP_MANAGED_CACHE'))
		{
			global $CACHE_MANAGER;
			$CACHE_MANAGER->ClearByTag('bitrix24_left_menu');
		}
	}
}
