<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\AiAssistant\Tools\Support;

use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\Im\Dialog;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\Message\Params;
use Bitrix\Im\V2\MessageCollection;
use Bitrix\Im\V2\Permission\Action;
use Bitrix\Im\V2\Service\Context;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Result;
use Bitrix\Main\Validation\ValidationService;

final class ImToolContext
{
	private const CHAT_NOT_FOUND_MESSAGE = 'Chat was not found. Check the dialogId: you can find group chats with '
		. 'search_group_chats_by_name or private chats with search_private_chats_by_name.';
	private const PRIVATE_CHAT_NOT_FOUND_MESSAGE = 'Private chat with this user was not found: the conversation '
		. 'has not started yet. Use send_message to start it, or check the user ID with search_private_chats_by_name.';

	/**
	 * @throws McpException
	 */
	public function validateDto(
		object $dto,
		string $message = 'Invalid tool input. Check the parameter values against the tool schema and try again.',
	): void
	{
		/** @var ValidationService $validation */
		$validation = ServiceLocator::getInstance()->get('main.validation.service');
		$result = $validation->validate($dto);

		if ($result->isSuccess())
		{
			return;
		}

		$error = $result->getError();
		if ($error === null)
		{
			throw new McpException($message);
		}

		throw new McpException("{$message} {$error->getCode()}: {$error->getMessage()}");
	}

	/**
	 * Resolves an existing chat by dialogId without side effects: unlike Dialog::getChatId, a numeric (private)
	 * dialogId never creates a missing private chat here. Tools that must create one (send_message) do it themselves.
	 *
	 * @throws McpException
	 */
	public function resolveChatByDialogId(int $userId, ?string $dialogId): Chat
	{
		if ($dialogId === null || $dialogId === '')
		{
			throw new McpException(
				'Dialog id is required. Pass "dialogId": the user ID for a private chat (e.g. "123") '
				. 'or the chat ID prefixed with "chat" for a group chat (e.g. "chat456").',
			);
		}

		if (preg_match('/^\d+$/', $dialogId))
		{
			$chatId = $this->getExistingPrivateChatId($userId, (int)$dialogId);
			if ($chatId <= 0)
			{
				throw new McpException(self::PRIVATE_CHAT_NOT_FOUND_MESSAGE);
			}
		}
		else
		{
			$chatId = (int)Dialog::getChatId($dialogId, $userId);
			if ($chatId <= 0)
			{
				throw new McpException(self::CHAT_NOT_FOUND_MESSAGE);
			}
		}

		$chat = Chat::getInstance($chatId);
		if (!$chat->isExist())
		{
			throw new McpException(self::CHAT_NOT_FOUND_MESSAGE);
		}

		return $this->prepareChatContext($chat, $userId);
	}

	/**
	 * Returns the id of an already existing private chat with the given user, never creating one.
	 */
	private function getExistingPrivateChatId(int $userId, int $opponentId): int
	{
		if ($userId <= 0 || $opponentId <= 0)
		{
			return 0;
		}

		if ($opponentId === $userId)
		{
			// \CIMMessage::GetChatId ignores $createIfNotExists for the self dialog, so look it up directly.
			$favoriteChatResult = Chat\FavoriteChat::find(['TO_USER_ID' => $userId]);

			return $favoriteChatResult->hasResult() ? (int)$favoriteChatResult->getResult()['ID'] : 0;
		}

		return (int)\CIMMessage::GetChatId($userId, $opponentId, false);
	}

	public function prepareChatContext(Chat $chat, int $userId): Chat
	{
		return $chat->setContext((new Context())->setUserId($userId));
	}

	/**
	 * @throws McpException
	 */
	public function assertChatAccess(Chat $chat, int $userId): void
	{
		$this->prepareChatContext($chat, $userId);
		$this->throwIfFailed(
			$chat->checkAccess($userId),
			'Access to this chat is denied. The current user is not a member of the chat; '
			. 'check the dialogId or use a chat the user belongs to.',
		);
	}

	/**
	 * @throws McpException
	 */
	public function assertCanDo(Chat $chat, int $userId, Action $action, mixed $target = null): void
	{
		$this->prepareChatContext($chat, $userId);

		if (!$chat->canDo($action, $target))
		{
			throw new McpException(
				"Access to action {$action->value} is denied. The current user lacks the required role or permission "
				. 'in this chat; check the role with get_chat_members or ask a chat manager to perform this action.',
			);
		}
	}

	/**
	 * Reads one honest page of chat messages available to the user.
	 *
	 * The window, hasMore and nextCursor are computed over the raw DESC selection of `limit + 1` messages,
	 * while readability filtering is applied only to the window. This keeps pagination monotonic even when
	 * a whole page is filtered out: nextCursor still advances, so the next call makes forward progress.
	 *
	 * The cursor deliberately reuses the shared LAST_ID filter (parity with REST im.v2.Chat.Message.*):
	 * `ID < cursor` bounded by the cursor row's DATE_CREATE, while MessageCollection::find orders by
	 * (DATE_CREATE, ID). On history where ID order diverges from DATE_CREATE order (imported messages,
	 * edited dates) this keyset can skip such anomalous rows; fixing that requires a composite
	 * (DATE_CREATE, ID) keyset in MessageCollection::find, shared with REST.
	 *
	 * @param array $additionalFilter Extra message filter keys, e.g. ['SEARCH_MESSAGE' => $query].
	 * @return array{messages: Message[], hasMore: bool, nextCursor: ?int}
	 */
	public function getReadableMessagesPage(
		Chat $chat,
		int $userId,
		?int $exclusiveBeforeId,
		int $limit,
		array $additionalFilter = []
	): array
	{
		$filter = [
			'CHAT_ID' => $chat->getChatId(),
			'WITHOUT_SYSTEM_MESSAGE' => true,
		] + $additionalFilter;

		$startId = $chat->getStartId($userId);
		if ($startId > 0)
		{
			$filter['START_ID'] = $startId;
		}

		if ($exclusiveBeforeId !== null && $exclusiveBeforeId > 0)
		{
			$filter['LAST_ID'] = $exclusiveBeforeId;
		}

		$rawMessages = MessageCollection::find(
			$filter,
			['ID' => 'DESC'],
			$limit + 1,
			null,
			['ID', 'CHAT_ID', 'AUTHOR_ID', 'DATE_CREATE', 'MESSAGE'],
		);

		$rawList = array_values(iterator_to_array($rawMessages, false));
		$window = array_slice($rawList, 0, $limit);
		$hasMore = count($rawList) > $limit;

		$nextCursor = null;
		if ($hasMore)
		{
			$windowIds = array_filter(
				array_map(static fn (Message $message) => $message->getId(), $window),
				static fn ($id) => $id !== null,
			);
			$nextCursor = empty($windowIds) ? null : min($windowIds);
		}

		return [
			'messages' => $this->filterReadableMessages(MessageCollection::createFromArray($window), $userId),
			'hasMore' => $hasMore,
			'nextCursor' => $nextCursor,
		];
	}

	/**
	 * @return Message[]
	 */
	public function filterReadableMessages(MessageCollection $messages, int $userId): array
	{
		$messages->fillParams();

		$result = [];
		foreach ($messages as $message)
		{
			if ($this->isDeletedMessage($message))
			{
				continue;
			}

			if (!$message->checkAccess($userId)->isSuccess())
			{
				continue;
			}

			$result[] = $message;
		}

		return $result;
	}

	public function isDeletedMessage(Message $message): bool
	{
		return $message->getParams()->get(Params::IS_DELETED)->getValue() === true;
	}

	/**
	 * @throws McpException
	 */
	public function throwIfFailed(Result $result, string $defaultMessage): void
	{
		if ($result->isSuccess())
		{
			return;
		}

		$messages = $result->getErrorMessages();
		if (empty($messages))
		{
			throw new McpException($defaultMessage);
		}

		throw new McpException(implode(', ', $messages));
	}
}
