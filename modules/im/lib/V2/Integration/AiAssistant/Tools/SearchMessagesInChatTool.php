<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\AiAssistant\Tools;

use Bitrix\Im\V2\Integration\AiAssistant\Tools\Dto\SearchMessagesInChatDto;
use Bitrix\Im\V2\Integration\AiAssistant\Tools\Support\ImToolContext;
use Bitrix\Im\V2\Integration\AiAssistant\Tools\Support\ImToolFormatter;
use Bitrix\Im\V2\Integration\AiAssistant\ToolScope;

class SearchMessagesInChatTool extends BaseImTool
{
	private const DEFAULT_LIMIT = 10;
	private const MAX_LIMIT = 50;

	protected function getScope(): ToolScope
	{
		return ToolScope::Read;
	}

	public function getName(): string
	{
		return 'search_messages_in_chat';
	}

	public function getDescription(): string
	{
		return 'Full-text search of messages by their text within a single chat, restricted to messages the current '
			. 'user can see; matches are returned most-recent-first. System messages, deleted messages, messages sent '
			. 'before the current user joined, and inaccessible messages are excluded, so one page may contain fewer '
			. 'than "limit" items even when more matches exist below — rely on "hasMore", not on the item count. '
			. 'This tool searches only inside the one chat given by "dialogId" and never across chats. Read-only. '
			. 'In the response, chat.type is a one-letter chat type code (e.g. "C" for a group chat, "P" for a '
			. 'private chat).';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'dialogId' => [
					'description' => 'The dialog identifier. For a private chat use the user ID, for a group chat use chat<ID>.',
					'type' => 'string',
					'pattern' => '^(chat\d+|\d+)$',
				],
				'query' => [
					'description' => 'Text to search for within the chat messages (3-500 characters).',
					'type' => 'string',
					'minLength' => 3,
					'maxLength' => 500,
				],
				'limit' => [
					'description' => 'Maximum number of messages to return.',
					'type' => 'integer',
					'minimum' => 1,
					'maximum' => self::MAX_LIMIT,
					'default' => self::DEFAULT_LIMIT,
				],
				'lastId' => [
					'description' => 'Optional exclusive cursor: returns only matching messages older than this '
						. 'message ID (id < lastId). Omit it for the first page; to continue paging, pass the '
						. '"nextLastId" value from the previous response. This is the same kind of message-id cursor '
						. 'as "beforeMessageId" in get_last_messages.',
					'type' => 'integer',
					'minimum' => 1,
				],
			],
			'additionalProperties' => false,
			'required' => ['dialogId', 'query'],
		];
	}

	protected function executeStructuredTool(int $userId, ...$args): array
	{
		$context = new ImToolContext();
		$formatter = new ImToolFormatter();

		$dto = SearchMessagesInChatDto::createFromParams($args);
		$context->validateDto($dto);

		$chat = $context->resolveChatByDialogId($userId, $dto->dialogId);
		$context->assertChatAccess($chat, $userId);

		// SEARCH_MESSAGE is the same unindexed LIKE/ILIKE scan as REST im.v2.Chat.Message.search, bounded by
		// the (CHAT_ID, DATE_CREATE) index and the page window. The fulltext b_im_message_index cannot be used
		// here until the V2 send pipeline populates it: today only legacy CIMMessenger::Add and message edits
		// index new rows, so joining the index would silently drop messages sent via the V2 path.
		$page = $context->getReadableMessagesPage(
			$chat,
			$userId,
			$dto->lastId ?? null,
			$dto->limit,
			['SEARCH_MESSAGE' => $dto->query],
		);

		$readableMessages = $page['messages'];

		$formatter->preloadUsers(array_map(static fn ($message) => $message->getAuthorId(), $readableMessages));

		return [
			'chat' => $formatter->formatChat($chat, $userId),
			'query' => $dto->query,
			'messages' => array_map(
				static fn ($message) => $formatter->formatMessage($message),
				$readableMessages,
			),
			'pagination' => [
				'limit' => $dto->limit,
				'lastId' => $dto->lastId ?? null,
				'nextLastId' => $page['nextCursor'],
				'hasMore' => $page['hasMore'],
			],
		];
	}
}
