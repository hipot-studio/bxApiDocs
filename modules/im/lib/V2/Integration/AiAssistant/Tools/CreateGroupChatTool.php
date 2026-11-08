<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\AiAssistant\Tools;

use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\Im\Text;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\Add\ChatCreateFields;
use Bitrix\Im\V2\Chat\ChatFactory;
use Bitrix\Im\V2\Common\Normalizer;
use Bitrix\Im\V2\Integration\AiAssistant\Tools\Dto\CreateGroupChatDto;
use Bitrix\Im\V2\Integration\AiAssistant\Tools\Support\ImToolContext;
use Bitrix\Im\V2\Integration\AiAssistant\ToolScope;

class CreateGroupChatTool extends BaseImTool
{
	private const MAX_USERS = 50;
	private const CREATE_FAILED_MESSAGE = 'Unable to create the chat. Check that the title is valid and the user IDs '
		. 'exist, then try again; if the problem persists, report the failure to the user instead of retrying.';

	protected function getScope(): ToolScope
	{
		return ToolScope::Write;
	}

	public function getName(): string
	{
		return 'create_group_chat';
	}

	public function getDescription(): string
	{
		return "Creates a new closed (invite-only) group chat with the given title and members; the current user becomes its owner.
			Do not use this tool to start a one-to-one private chat with a single person: for that, use send_message with the user's ID as the dialogId.
			Titles are not unique: calling this tool again with the same title creates another separate chat, so do not use it to look up or reuse an existing chat.
			IMPORTANT: This action creates a new chat and requires user confirmation.";
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'title' => [
					'description' => 'The title of the group chat.',
					'type' => 'string',
					'maxLength' => 255,
				],
				'userIds' => [
					'description' => 'Optional list of user IDs to add as members besides yourself. The current user is '
						. 'always added automatically as the owner. If omitted or empty, a chat containing only you is created.',
					'type' => 'array',
					'items' => [
						'type' => 'integer',
						'minimum' => 1,
					],
					'maxItems' => self::MAX_USERS,
				],
				'description' => [
					'description' => 'Optional description of the group chat.',
					'type' => 'string',
					'maxLength' => 2000,
				],
			],
			'additionalProperties' => false,
			'required' => ['title'],
		];
	}

	/**
	 * @throws McpException
	 */
	protected function executeStructuredTool(int $userId, ...$args): array
	{
		$context = new ImToolContext();

		$dto = CreateGroupChatDto::createFromParams($args);
		$context->validateDto($dto);

		// The schema's maxItems is not enforced by aiassistant's JsonSchemaValidator, so this raw-input
		// check is the actual limit enforcement; it runs before normalization to reject oversized
		// payloads without paying the per-item normalization cost first.
		if (count($dto->userIds) > self::MAX_USERS)
		{
			throw new McpException(
				'Too many users requested. Pass at most ' . self::MAX_USERS
				. ' user IDs; create the chat with a smaller list and add the rest with add_users_to_chat.',
			);
		}

		$userIds = Normalizer::toUniquePositiveIntegers($dto->userIds);

		$fields = new ChatCreateFields(
			type: Chat::IM_TYPE_CHAT,
			title: $dto->title,
			description: $dto->description !== '' ? $dto->description : null,
			authorId: $userId,
			users: $userIds,
		);

		$context->throwIfFailed(
			$fields->checkAccess($userId),
			'Access to create a chat is denied. The current user is not allowed to create group chats on this portal; '
			. 'ask a portal administrator to grant this permission.',
		);

		$addResult = ChatFactory::getInstance()->withContextUser($userId)->addChat($fields->toArray());
		if (!$addResult->isSuccess())
		{
			throw new McpException(self::CREATE_FAILED_MESSAGE);
		}

		$chat = $addResult->getChat();
		if ($chat === null)
		{
			throw new McpException(self::CREATE_FAILED_MESSAGE);
		}

		return [
			'chatId' => $chat->getChatId(),
			'dialogId' => $chat->getDialogId($userId),
			// Decoded the same way as ImToolFormatter::formatChat(), so the title matches the read tools.
			'title' => Text::decodeEmoji($chat->getTitle() ?? ''),
			'memberUserIds' => array_values($chat->getRelations()->getUserIds()),
		];
	}
}
