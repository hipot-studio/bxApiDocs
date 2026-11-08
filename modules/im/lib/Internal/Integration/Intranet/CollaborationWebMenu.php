<?php

declare(strict_types=1);

namespace Bitrix\Im\Internal\Integration\Intranet;

use Bitrix\Intranet\Integration\Templates\Air;
use Bitrix\Main\Loader;

class CollaborationWebMenu
{
	public static function getMenu(): ?array
	{
		if (
			!Loader::includeModule('intranet')
			|| !class_exists(Air\CollaborationWebMenu::class)
			|| !is_callable([Air\CollaborationWebMenu::class, 'getMenu'])
		)
		{
			return null;
		}

		return Air\CollaborationWebMenu::getMenu('messenger');
	}
}
