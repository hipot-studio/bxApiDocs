<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Chat;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Integration\AiAssistant\AiAssistantService;
use Bitrix\Im\V2\Chat\Ai\AiAssistantPrivateChat;
use Bitrix\Main\DI\ServiceLocator;

/**
 * @phpstan-type FindChatType 'P'|'C'|'O'|'S'|'L'
 */
final class ChatClassResolver
{
	private ?AiAssistantService $aiAssistantService = null;

	/**
	 * @param array{
	 *     ID?: int,
	 *     TYPE?: string,
	 *     ENTITY_TYPE?: string,
	 *     MESSAGE_TYPE?: string,
	 *     FROM_USER_ID?: int,
	 *     TO_USER_ID?: int,
	 *     } $params
	 *
	 * @return class-string<Chat>
	 */
	public function resolveForInit(array $params): string
	{
		$type = $params['TYPE'] ?? $params['MESSAGE_TYPE'] ?? '';
		$entityType = $params['ENTITY_TYPE'] ?? '';

		return match (true)
		{
			$entityType === Chat::ENTITY_TYPE_FAVORITE || $entityType === 'PERSONAL' => FavoriteChat::class,
			$entityType === Chat::ENTITY_TYPE_GENERAL => GeneralChat::class,
			$entityType === Chat::ENTITY_TYPE_GENERAL_CHANNEL => GeneralChannel::class,
			$entityType === Chat::ENTITY_TYPE_LINE || $type === Chat::IM_TYPE_OPEN_LINE => OpenLineChat::class,
			$entityType === Chat::ENTITY_TYPE_LIVECHAT => OpenLineLiveChat::class,
			$entityType === Chat::ENTITY_TYPE_VIDEOCONF => VideoConfChat::class,
			$type === Chat::IM_TYPE_CHANNEL => ChannelChat::class,
			$type === Chat::IM_TYPE_OPEN_CHANNEL => OpenChannelChat::class,
			$type === Chat::IM_TYPE_OPEN => OpenChat::class,
			$type === Chat::IM_TYPE_SYSTEM => NotifyChat::class,
			$type === Chat::IM_TYPE_PRIVATE => $this->resolvePrivateChatType($params),
			$type === Chat::IM_TYPE_CHAT => GroupChat::class,
			$type === Chat::IM_TYPE_COMMENT => CommentChat::class,
			$type === Chat::IM_TYPE_COPILOT => CopilotChat::class,
			$type === Chat::IM_TYPE_COLLAB => CollabChat::class,
			$type === Chat::IM_TYPE_EXTERNAL => ExternalChat::class,
			default => NullChat::class,
		};
	}

	/**
	 * @param array{
	 *     TYPE?: FindChatType,
	 *     ENTITY_TYPE?: string,
	 *     MESSAGE_TYPE?: FindChatType,
	 *     FROM_USER_ID?: int,
	 *     TO_USER_ID?: int,
	 *     } $params
	 *
	 * @return class-string<Chat>
	 */
	public function resolveForFind(array $params): string
	{
		$type = $params['TYPE'] ?? $params['MESSAGE_TYPE'] ?? '';
		$entityType = $params['ENTITY_TYPE'] ?? '';

		return match (true)
		{
			$entityType === Chat::ENTITY_TYPE_GENERAL => GeneralChat::class,
			$type === Chat::IM_TYPE_SYSTEM => NotifyChat::class,
			$type === Chat::IM_TYPE_PRIVATE => $this->resolvePrivateChatType($params),
			in_array($type, [Chat::IM_TYPE_CHAT, Chat::IM_TYPE_OPEN_LINE, Chat::IM_TYPE_OPEN], true) => Chat::class,
			default => NullChat::class,
		};
	}

	private function resolvePrivateChatType(array $params): string
	{
		if ($this->isPrivateAiAssistantChat($params))
		{
			return AiAssistantPrivateChat::class;
		}

		if (
			isset($params['TO_USER_ID'], $params['FROM_USER_ID'])
			&& (int)$params['TO_USER_ID'] === (int)$params['FROM_USER_ID']
		)
		{
			return FavoriteChat::class;
		}

		return PrivateChat::class;
	}

	private function isPrivateAiAssistantChat(array $params): bool
	{
		$botId = $this->getAiAssistantService()->getBotId();

		if (($params['TYPE'] ?? '') !== Chat::IM_TYPE_PRIVATE || !$botId)
		{
			return false;
		}

		$users = [(int)($params['FROM_USER_ID'] ?? 0), (int)($params['TO_USER_ID'] ?? 0)];

		return
			($params['ENTITY_TYPE'] ?? '') === Chat::ENTITY_TYPE_PRIVATE_AI_ASSISTANT
			|| in_array($botId, $users, true)
		;
	}

	private function getAiAssistantService(): AiAssistantService
	{
		if ($this->aiAssistantService === null)
		{
			$this->aiAssistantService = ServiceLocator::getInstance()->get(AiAssistantService::class);
		}

		return $this->aiAssistantService;
	}
}
