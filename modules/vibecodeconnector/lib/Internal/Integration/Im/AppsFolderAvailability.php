<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Integration\Im;

use Bitrix\Im\V2\Application\Features;
use Bitrix\Main\Loader;

/**
 * Whether the messenger collects the app chats in a folder of its own. The toggle is owned by im
 * (Features::isVibecodeAppsFolderAvailable); this module only reads it, and a messenger without
 * the predicate has no such folder.
 */
class AppsFolderAvailability
{
	public function isEnabled(): bool
	{
		if (!Loader::includeModule('im') || !method_exists(Features::class, 'isVibecodeAppsFolderAvailable'))
		{
			return false;
		}

		return Features::isVibecodeAppsFolderAvailable();
	}
}
