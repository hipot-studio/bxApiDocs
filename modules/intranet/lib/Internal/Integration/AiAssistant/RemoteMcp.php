<?php

declare(strict_types = 1);

namespace Bitrix\Intranet\Internal\Integration\AiAssistant;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;

class RemoteMcp
{
	public function isLeftMenuItemAvailable(): bool
	{
		if (!Loader::includeModule('aiassistant') || !Loader::includeModule('bitrix24'))
		{
			return false;
		}

		return Option::get('aiassistant', 'left_menu_mcp', 'N') === 'Y';
	}
}
