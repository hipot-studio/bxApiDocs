<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Integration\Im\Service;

use Bitrix\Im\Bot;
use Bitrix\Main\Loader;

class BotService
{
	public function getExternalAuthId(): ?string
	{
		if (!$this->isAvailable())
		{
			return null;
		}

		return Bot::EXTERNAL_AUTH_ID;
	}

	private function isAvailable(): bool
	{
		return Loader::includeModule('im');
	}
}
