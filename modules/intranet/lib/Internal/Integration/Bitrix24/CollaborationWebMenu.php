<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\Bitrix24;

use Bitrix\Bitrix24;
use Bitrix\Intranet\Internal\Config;
use Bitrix\Main\Config\Feature;
use Bitrix\Main\Loader;

class CollaborationWebMenu
{
	public static function isTopMenuEnabled(): bool
	{
		if (self::isEnabled())
		{
			return true;
		}

		if (!class_exists(Feature::class) || !is_callable([Feature::class, 'isEnabled']))
		{
			return false;
		}

		if (Loader::includeModule('bitrix24'))
		{
			return class_exists(Bitrix24\Internal\Config\Feature\CollaborationTopMenuFlag::class)
				&& Feature::isEnabled(Bitrix24\Internal\Config\Feature\CollaborationTopMenuFlag::class);
		}

		return class_exists(Config\Feature\CollaborationTopMenuFlag::class)
			&& Feature::isEnabled(Config\Feature\CollaborationTopMenuFlag::class);
	}

	public static function isEnabled(): bool
	{
		if (!class_exists(Feature::class) || !is_callable([Feature::class, 'isEnabled']))
		{
			return false;
		}

		if (Loader::includeModule('bitrix24'))
		{
			return class_exists(Bitrix24\Internal\Config\Feature\CollaborationWebMenuFlag::class)
				&& Feature::isEnabled(Bitrix24\Internal\Config\Feature\CollaborationWebMenuFlag::class);
		}

		return class_exists(Config\Feature\CollaborationWebMenuFlag::class)
			&& Feature::isEnabled(Config\Feature\CollaborationWebMenuFlag::class);
	}
}
