<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\AiAssistant\Tools;

use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\Im\V2\Integration\AiAssistant\Tools\Dto\MarkChatAsReadDto;
use Bitrix\Im\V2\Integration\AiAssistant\Tools\Support\ImToolContext;
use Bitrix\Im\V2\Integration\AiAssistant\Tools\Support\ImToolFormatter;
use Bitrix\Im\V2\Integration\AiAssistant\ToolScope;
use Bitrix\Im\V2\Permission\Action;
use Bitrix\Im\V2\Reading\Reader;
use Bitrix\Main\DI\ServiceLocator;

class MarkChatAsReadTool extends BaseImTool
{
	protected function getScope(): ToolScope
	{
		return ToolScope::Write;
	}

	public function getName(): string
	{
		return 'mark_chat_as_read';
	}

	public function getDescription(): string
	{
		return "Marks every message in a single specified chat as read for the current user.
			Descendant chats up to two parent-link levels deep (for example comment chats attached to this chat or to its child chats) are scheduled to be marked as read by a deferred background job:
			the tool responds right after the target chat itself is read, so unread counters of descendant chats may stay non-zero for a short while.
			Chats nested deeper than two levels and unrelated chats are not affected.
			Use this tool only for the one chat identified by dialogId; it cannot mark several unrelated chats or all chats at once.
			In the response, chat.type is a one-letter chat type code (e.g. \"C\" for a group chat, \"P\" for a private chat).
			IMPORTANT: This action changes the read state of the chat and its descendant chats up to two levels deep and requires user confirmation.";
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
			],
			'additionalProperties' => false,
			'required' => ['dialogId'],
		];
	}

	/**
	 * @throws McpException
	 */
	protected function executeStructuredTool(int $userId, ...$args): array
	{
		$context = new ImToolContext();
		$formatter = new ImToolFormatter();

		$dto = MarkChatAsReadDto::createFromParams($args);
		$context->validateDto($dto);

		$chat = $context->resolveChatByDialogId($userId, $dto->dialogId);
		$context->assertCanDo($chat, $userId, Action::ReadChat);

		// readAllInChat() reads the target chat synchronously; descendant chats are cleared later
		// by a deferred Reader::readChildren() background job (up to two PARENT_ID levels deep,
		// ChatAncestorNavigator::DEFAULT_DEPTH). The tool description mirrors exactly these
		// semantics — do not promise a synchronous full cascade.
		/** @var Reader $reader */
		$reader = ServiceLocator::getInstance()->get(Reader::class);
		$result = $reader->readAllInChat($chat->getChatId(), $userId);

		if (!$result->isSuccess())
		{
			throw new McpException(
				'Unable to mark the chat as read. Make sure the chat still exists and the current user is a member '
				. 'of it, then try again.',
			);
		}

		return [
			'chat' => $formatter->formatChat($chat, $userId),
			'result' => true,
		];
	}
}
