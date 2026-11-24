<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Chat\Copilot;

use Bitrix\Im\V2\Analytics\VoiceWidgetLabels;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\Add\AddResult;
use Bitrix\Im\V2\Chat\ChatError;
use Bitrix\Im\V2\Chat\ChatFactory;
use Bitrix\Im\V2\Chat\CopilotChat;
use Bitrix\Im\V2\Chat\Param\Params;
use Bitrix\Im\V2\Integration\AI\RoleManager;
use Bitrix\Im\V2\Permission;
use Bitrix\Im\V2\Permission\GlobalAction;

/**
 * Idempotent reserve-chat lookup for CoPilot: a client that lost the response of a chat-creation
 * request retries with the same attempt key instead of guessing whether the chat was created.
 */
final class KeyedChatService
{
	public const ENTITY_TYPE = 'COPILOT_REQUEST';

	// \z and not $: in PCRE $ also matches before a trailing newline, so "<uuid>\n" would pass
	// validation and reach ENTITY_ID and the named lock.
	private const CREATION_KEY_PATTERN =
		'/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/'
	;

	public function getOrCreate(
		int $userId,
		string $creationKey,
		?string $copilotMainRole = null,
		?VoiceWidgetLabels $voiceWidgetLabels = null,
	): AddResult
	{
		$result = new AddResult();

		if (!preg_match(self::CREATION_KEY_PATTERN, $creationKey))
		{
			return $result->addError(new ChatError(ChatError::WRONG_PARAMETER));
		}

		// Checked here and not only inside CopilotChat::add(): the reuse path below never calls
		// add() at all, so without this an already-created chat would still be handed back after
		// the copilot bot or AI engine became unavailable.
		$availability = CopilotChat::checkCopilotAvailability();
		if (!$availability->isSuccess())
		{
			return $result->addErrors($availability->getErrors());
		}

		if (!Permission::canDoGlobalAction($userId, GlobalAction::CreateChat, ['TYPE' => Chat::IM_TYPE_COPILOT]))
		{
			return $result->addError(new ChatError(ChatError::ACCESS_DENIED));
		}

		$roleCode = (new RoleManager())->getValidRoleCode($copilotMainRole);

		$chatParams = [
			[
				'PARAM_NAME' => Params::COPILOT_MAIN_ROLE,
				'PARAM_VALUE' => $roleCode,
			],
		];
		$fields = [
			'TYPE' => Chat::IM_TYPE_COPILOT,
			'ENTITY_TYPE' => self::ENTITY_TYPE,
			'ENTITY_ID' => $creationKey,
			'AUTHOR_ID' => $userId,
		];

		// The same pair im.v2.Chat.add builds for a chat of the mobile voice widget (see ChatCreateFields):
		// the origin stays on the chat, the labels go to its create_new_chat event. Both are consumed only
		// when the chat is really created - the reuse branch of the factory never reaches addChat().
		if ($voiceWidgetLabels !== null)
		{
			$chatParams[] = [
				'PARAM_NAME' => Params::COPILOT_ORIGIN,
				'PARAM_VALUE' => VoiceWidgetLabels::ORIGIN,
			];
			$fields['COPILOT_ANALYTICS'] = $voiceWidgetLabels;
		}
		$fields['CHAT_PARAMS'] = $chatParams;

		$addResult = ChatFactory::getInstance()->withContextUser($userId)->addUniqueChatPerAuthor($fields);

		if (!$addResult->isSuccess())
		{
			return $addResult;
		}

		// The reuse branch of addUniqueChatPerAuthor() only matches on (ENTITY_TYPE, ENTITY_ID,
		// AUTHOR_ID): it says nothing about the chat's current type or membership. A chat found this
		// way is only ever handed back if it is still a CopilotChat the caller belongs to; a stale or
		// foreign match is refused rather than silently exposed.
		$chat = $addResult->getChat();
		$isReuse = (bool)($addResult->getResult()['ALREADY_EXISTS'] ?? false);
		if ($isReuse)
		{
			if (!($chat instanceof CopilotChat) || !$chat->checkAccess($userId)->isSuccess())
			{
				return (new AddResult())->addError(new ChatError(ChatError::ACCESS_DENIED));
			}

			return $addResult;
		}

		// GroupChat::add() skips greeting messages for any chat that has an ENTITY_TYPE, so the welcome
		// banner im.v2.Chat.add posts is sent here instead. Whether it is really posted - and with it the
		// author's recent row - is decided by sendBanner() itself, exactly as on the standard path.
		if ($chat instanceof CopilotChat)
		{
			$chat->sendBanner($userId);
		}

		return $addResult;
	}
}
