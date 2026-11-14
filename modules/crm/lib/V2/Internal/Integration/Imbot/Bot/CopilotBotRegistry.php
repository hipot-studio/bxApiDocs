<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\Imbot\Bot;

use Bitrix\Imbot\Bot\CopilotChatBot;
use Bitrix\Main\Loader;

/**
 * The copilot bot of the portal, as `imbot` registers it.
 *
 * The identifier is resolved statically inside the module and cannot be raised or substituted, so the
 * whole of the conversation with `imbot` is this one call - kept here because a message written as the
 * bot is sent through `aiassistant`, and the two modules are two integrations.
 *
 * @internal
 */
class CopilotBotRegistry
{
	/**
	 * @throws \Bitrix\Main\LoaderException the bots are not available on this portal
	 * @throws \RuntimeException the copilot bot is not registered
	 */
	public function requireBotId(): int
	{
		Loader::requireModule('imbot');

		$botId = (int)CopilotChatBot::getBotId();
		if ($botId <= 0)
		{
			throw new \RuntimeException('The copilot bot is not registered on this portal');
		}

		return $botId;
	}
}
