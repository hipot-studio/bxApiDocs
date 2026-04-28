<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Event;

use Bitrix\Im\Bot;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Entity\Bot\BotItem;
use Bitrix\Im\V2\Entity\User\User;
use Bitrix\Im\V2\Message;
use Bitrix\Main\Engine\Response\Converter;

class EventPayload
{
	public function messageAdd(int $messageId, array $messageFields): array
	{
		$chatId = $this->resolveChatId($messageFields);

		return [
			'message' => $this->loadMessageRest($messageId),
			'chat' => $this->loadChatRest($chatId),
			'user' => $this->loadUserRest(
				(int)($messageFields['FROM_USER_ID'] ?? $messageFields['AUTHOR_ID'] ?? 0)
			),
			'language' => $this->getLanguage(),
		];
	}

	public function messageUpdate(int $messageId, array $messageFields): array
	{
		return $this->messageAdd($messageId, $messageFields);
	}

	public function messageDelete(int $messageId, array $messageFields): array
	{
		$chatId = $this->resolveChatId($messageFields);

		return [
			'messageId' => $messageId,
			'chat' => $this->loadChatRest($chatId),
			'user' => $this->loadUserRest(
				(int)($messageFields['FROM_USER_ID'] ?? $messageFields['AUTHOR_ID'] ?? 0)
			),
			'language' => $this->getLanguage(),
		];
	}

	public function welcomeMessage(string $dialogId, array $joinFields): array
	{
		$chatId = (int)($joinFields['CHAT_ID'] ?? $joinFields['TO_CHAT_ID'] ?? 0);
		$userId = (int)($joinFields['USER_ID'] ?? $joinFields['FROM_USER_ID'] ?? 0);

		return [
			'dialogId' => $dialogId,
			'chat' => $this->loadChatRest($chatId),
			'user' => $this->loadUserRest($userId),
			'language' => $this->getLanguage(),
		];
	}

	public function botDelete(int $botId): array
	{
		return [
			'bot' => $this->loadBotRest($botId),
		];
	}

	public function contextGet(string $dialogId, array $params): array
	{
		$chatId = (int)($params['CHAT_ID'] ?? 0);

		return [
			'dialogId' => $dialogId,
			'context' => $params['CONTEXT'] ?? [],
			'chat' => $chatId > 0 ? $this->loadChatRest($chatId) : [],
			'user' => $this->loadUserRest(
				(int)($params['USER_ID'] ?? $params['FROM_USER_ID'] ?? 0)
			),
			'language' => $this->getLanguage(),
		];
	}

	public function commandAdd(array $commandData, int $messageId, array $messageFields): array
	{
		$chatId = (int)($messageFields['TO_CHAT_ID'] ?? $messageFields['CHAT_ID'] ?? 0);

		return [
			'command' => [
				'id' => (int)($commandData['COMMAND_ID'] ?? $commandData['ID'] ?? 0),
				'command' => '/' . ($commandData['COMMAND'] ?? ''),
				'params' => $commandData['EXEC_PARAMS'] ?? '',
				'context' => mb_strtolower($commandData['CONTEXT'] ?? ''),
			],
			'message' => $this->loadMessageRest($messageId),
			'chat' => $this->loadChatRest($chatId),
			'user' => $this->loadUserRest(
				(int)($messageFields['FROM_USER_ID'] ?? $messageFields['AUTHOR_ID'] ?? 0)
			),
			'language' => $this->getLanguage(),
		];
	}

	public function reactionChange(int $messageId, array $params): array
	{
		$chatId = (int)($params['CHAT_ID'] ?? 0);
		$userId = (int)($params['USER_ID'] ?? $params['REACTION_AUTHOR_ID'] ?? 0);

		$action = $params['REACTION_TYPE'] ?? '';
		$action = match ($action)
		{
			'ADD' => 'add',
			'DELETE' => 'delete',
			default => $action,
		};

		$reactionCode = $params['REACTION'] ?? '';
		if ($reactionCode !== '')
		{
			$reactionCode = (new Converter(Converter::TO_CAMEL | Converter::LC_FIRST))
				->process($reactionCode);
		}

		return [
			'reaction' => $reactionCode,
			'action' => $action,
			'message' => $this->loadMessageRest($messageId),
			'chat' => $chatId > 0 ? $this->loadChatRest($chatId) : [],
			'user' => $this->loadUserRest($userId),
			'language' => $this->getLanguage(),
		];
	}

	public function loadBotRest(int $botId): array
	{
		if ($botId <= 0)
		{
			return [];
		}

		$botItem = BotItem::createFromId($botId, true);
		if ($botItem === null)
		{
			return ['id' => $botId];
		}

		return $botItem->toRestFormat();
	}

	public function loadMessageRest(int $messageId): array
	{
		if ($messageId <= 0)
		{
			return [];
		}

		$message = new Message($messageId);
		if ($message->getId() === null)
		{
			return ['id' => $messageId];
		}

		return $message->toRestFormat(['MESSAGE_ONLY_COMMON_FIELDS' => true]);
	}

	public function resolveChatId(array $messageFields): int
	{
		return (int)($messageFields['TO_CHAT_ID'] ?? $messageFields['CHAT_ID'] ?? 0);
	}

	public function loadChatRest(int $chatId, int $contextUserId = 0): array
	{
		if ($chatId <= 0)
		{
			return [];
		}

		$chat = Chat::getInstance($chatId);
		if ($chat === null || $chat->getId() === null)
		{
			return ['id' => $chatId];
		}

		$result = $chat->toRestFormat(['CHAT_SHORT_FORMAT' => true]);

		if ($contextUserId > 0 && $chat instanceof Chat\PrivateChat)
		{
			$result['dialogId'] = $chat->getDialogId($contextUserId);
		}

		return $result;
	}

	public function loadUserRest(int $userId): array
	{
		if ($userId <= 0)
		{
			return [];
		}

		$user = User::getInstance($userId);
		if ($user === null || $user->getId() === null)
		{
			return ['id' => $userId];
		}

		return $user->toRestFormat(['WITHOUT_ONLINE' => true]);
	}

	private function getLanguage(): string
	{
		return Bot::getDefaultLanguage();
	}
}
