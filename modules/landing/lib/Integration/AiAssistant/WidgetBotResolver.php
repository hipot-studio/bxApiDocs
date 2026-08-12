<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant;

use Bitrix\AiAssistant\Core\Im\ImBot;
use Bitrix\Main\Loader;
use Throwable;

final class WidgetBotResolver
{
	public function resolve(): int
	{
		if (
			!Loader::includeModule('im')
			|| !Loader::includeModule('aiassistant')
			|| !Loader::includeModule('imbot')
		)
		{
			return 0;
		}

		try
		{
			$botId = ImBot::getBotId();
			if ($botId > 0)
			{
				return $botId;
			}

			return max(0, (int)ImBot::register());
		}
		catch (Throwable)
		{
			return 0;
		}
	}
}
