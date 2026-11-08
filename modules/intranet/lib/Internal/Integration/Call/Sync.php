<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\Call;

use Bitrix\Call\Settings;
use Bitrix\Main\Loader;

class Sync
{
	public static function isAvailable(): bool
	{
		return Loader::includeModule('call')
			&& class_exists(Settings::class)
			&& is_callable([Settings::class, 'isSyncPresetEnabled'])
			&& Settings::isSyncPresetEnabled();
	}
}
