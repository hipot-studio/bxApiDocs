<?php

namespace Bitrix\Im\V2\Entity\Command;

use Bitrix\Im\V2\Chat;

/**
 * Single source of truth for command visibility rules.
 *
 * Both the legacy execution path (Bitrix\Im\Command::findCommands) and the chat
 * command aggregator (ChatCommandList) must route their common/non-common/openline
 * decision through here, so the discovery list never diverges from what actually
 * executes on send.
 *
 * The method takes only primitives: the openline flag is pre-resolved by the caller
 * (batch read for the aggregator, single-bot map for findCommands), never resolved
 * per row inside the policy.
 */
class CommandAccessPolicy
{
	/**
	 * @param string $common Raw 'Y'/'N' flag of the command.
	 * @param int $botId Owner bot id of the command.
	 * @param int[] $participantBotIds Bot ids present in the chat (singleton [TO_USER_ID] for 1:1).
	 * @param string|null $chatEntityType Chat entity type (Chat::ENTITY_TYPE_*), or null.
	 * @param array<int, bool> $openlineByBotId Pre-resolved botId => isOpenline map (only consulted for LINES).
	 */
	public static function isVisible(
		string $common,
		int $botId,
		array $participantBotIds,
		?string $chatEntityType,
		array $openlineByBotId
	): bool
	{
		if ($chatEntityType === Chat::ENTITY_TYPE_LIVECHAT)
		{
			return false;
		}

		if ($chatEntityType === Chat::ENTITY_TYPE_LINE && ($openlineByBotId[$botId] ?? false) !== true)
		{
			return false;
		}

		if ($common === 'Y')
		{
			return true;
		}

		return in_array($botId, $participantBotIds, true);
	}
}
