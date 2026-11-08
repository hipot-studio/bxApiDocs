<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\Im;

use Bitrix\Im\V2\Application\Navigation\Menu;
use Bitrix\Im\V2\Application\Context;
use Bitrix\Im\V2\Application\Features;
use Bitrix\Intranet\Integration\Templates\Air\ChatMenu;
use Bitrix\Main\Loader;

class CollaborationMenu
{
	public static function getItems(): array
	{
		return self::isSupported() ? ChatMenu::getMenuItems() : [];
	}

	public static function isAvailable(): bool
	{
		return self::isSupported()
			&& class_exists(Features::class)
			&& is_callable([Features::class, 'isChatFoldersWebAvailableForUser'])
			&& Features::isChatFoldersWebAvailableForUser();
	}

	private static function isSupported(): bool
	{
		if (
			!Loader::includeModule('im')
			|| !class_exists(Menu::class)
			|| !is_callable([Menu::class, 'getInstance'])
			|| !method_exists(Menu::class, 'getMenuItems')
			|| !class_exists(Context::class)
			|| !is_callable([Context::class, 'getCurrent'])
		)
		{
			return false;
		}

		$context = Context::getCurrent();
		if (
			!is_callable([$context, 'isMobile'])
			|| $context->isMobile()
		)
		{
			return false;
		}

		return true;
	}
}
