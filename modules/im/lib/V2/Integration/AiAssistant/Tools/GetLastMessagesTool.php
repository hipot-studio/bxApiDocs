<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\AiAssistant\Tools;

use Bitrix\Im\V2\Integration\AiAssistant\Tools\Dto\GetLastMessagesDto;
use Bitrix\Im\V2\Integration\AiAssistant\Tools\Support\ImToolContext;
use Bitrix\Im\V2\Integration\AiAssistant\Tools\Support\ImToolFormatter;
use Bitrix\Im\V2\Integration\AiAssistant\ToolScope;

class GetLastMessagesTool extends BaseImTool
{
	private const DEFAULT_LIMIT = 20;
	private const MAX_LIMIT = 50;

	protected function getScope(): ToolScope
	{
		return ToolScope::Read;
	}

	public function getName(): string
	{
		return 'get_last_messages';
	}

	public function getDescription(): string
	{
		return 'Returns the most recent messages of a single chat, ordered oldest-to-newest, as seen by the current user. '
			. '"Last" means most recent by creation time, not unread. System messages, deleted messages, messages sent '
			. 'before the current user joined, and messages the user cannot access are excluded, so one page may contain '
			. 'fewer than "limit" items even when older messages still exist — rely on "hasMore", never on the item count. '
			. 'To page further into older history, pass the returned "nextBeforeMessageId" back as "beforeMessageId". '
			. 'Read-only: this tool does not change the chat read state. In the response, chat.type is a one-letter '
			. 'chat type code (e.g. "C" for a group chat, "P" for a private chat).';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'dialogId' => [
					'description' => 'The dialog identifier. For a private chat use the user ID (e.g. "123"); '
						. 'for a group chat use the chat ID prefixed with "chat" (e.g. "chat456").',
					'type' => 'string',
					'pattern' => '^(chat\d+|\d+)$',
				],
				'limit' => [
					'description' => 'Maximum number of messages per page (1-' . self::MAX_LIMIT . ', default '
						. self::DEFAULT_LIMIT . '). Fewer may be returned when messages are filtered out; '
						. 'check "hasMore" to know whether older messages remain.',
					'type' => 'integer',
					'minimum' => 1,
					'maximum' => self::MAX_LIMIT,
					'default' => self::DEFAULT_LIMIT,
				],
				'beforeMessageId' => [
					'description' => 'Optional exclusive cursor: returns only messages older than this message ID '
						. '(id < beforeMessageId). Omit it to start from the most recent message; to continue paging, '
						. 'pass the "nextBeforeMessageId" value from the previous response.',
					'type' => 'integer',
					'minimum' => 1,
				],
			],
			'additionalProperties' => false,
			'required' => ['dialogId'],
		];
	}

	protected function executeStructuredTool(int $userId, ...$args): array
	{
		$context = new ImToolContext();
		$formatter = new ImToolFormatter();

		$dto = GetLastMessagesDto::createFromParams($args);
		$context->validateDto($dto);

		$chat = $context->resolveChatByDialogId($userId, $dto->dialogId);
		$context->assertChatAccess($chat, $userId);

		$page = $context->getReadableMessagesPage(
			$chat,
			$userId,
			$dto->beforeMessageId ?? null,
			$dto->limit,
		);

		// The page keeps the (DATE_CREATE DESC, ID DESC) order of MessageCollection::find, so reversing it
		// yields the declared oldest-to-newest order by creation time; re-sorting by ID would break it for
		// messages whose ID order differs from DATE_CREATE (e.g. imported history).
		$readableMessages = array_reverse($page['messages']);

		$formatter->preloadUsers(array_map(static fn ($message) => $message->getAuthorId(), $readableMessages));

		return [
			'chat' => $formatter->formatChat($chat, $userId),
			'messages' => array_map(
				static fn ($message) => $formatter->formatMessage($message),
				$readableMessages,
			),
			'pagination' => [
				'limit' => $dto->limit,
				'beforeMessageId' => $dto->beforeMessageId ?? null,
				'nextBeforeMessageId' => $page['nextCursor'],
				'hasMore' => $page['hasMore'],
			],
		];
	}
}
