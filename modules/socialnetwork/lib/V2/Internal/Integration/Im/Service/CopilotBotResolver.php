<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Integration\Im\Service;

use Bitrix\Imbot\Bot\CopilotChatBot;
use Bitrix\Main\Loader;

class CopilotBotResolver
{
	public function getCopilotBotId(): ?int
	{
		if (!$this->isAvailable())
		{
			return null;
		}

		return CopilotChatBot::getBotId() ?: null;
	}

	private function isAvailable(): bool
	{
		return Loader::includeModule('imbot');
	}
}
