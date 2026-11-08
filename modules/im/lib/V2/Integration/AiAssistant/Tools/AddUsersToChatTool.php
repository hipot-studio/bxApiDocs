<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\AiAssistant\Tools;

use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Common\Normalizer;
use Bitrix\Im\V2\Integration\AiAssistant\Tools\Dto\AddUsersToChatDto;
use Bitrix\Im\V2\Integration\AiAssistant\Tools\Support\ImToolContext;
use Bitrix\Im\V2\Integration\AiAssistant\ToolScope;
use Bitrix\Im\V2\Permission\Action;
use Bitrix\Im\V2\Relation\AddUsersConfig;
use Bitrix\Im\V2\RelationCollection;

class AddUsersToChatTool extends BaseImTool
{
	private const MAX_USERS = 50;

	protected function getScope(): ToolScope
	{
		return ToolScope::Write;
	}

	public function getName(): string
	{
		return 'add_users_to_chat';
	}

	public function getDescription(): string
	{
		return "Adds the specified users to an existing group chat identified by dialogId.
			If the chat is nested under a parent chat (for example a task chat inside a collab), the users are also added to every parent chat up the chain, and the current user must be allowed to add users in those parent chats as well.
			Some requested users may be silently skipped by access rules (for example extranet, inactive or non-existent users); such users are reported in notAddedUserIds. The response fields are best-effort and may be affected by concurrent membership changes.
			IMPORTANT: This action changes the chat membership and requires user confirmation.";
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'dialogId' => [
					'description' => 'The dialog identifier of an existing group chat, in the form chat<ID> '
						. '(e.g. "chat456"). One-to-one private chats are not supported by this tool.',
					'type' => 'string',
					'pattern' => '^chat\d+$',
				],
				'userIds' => [
					'description' => 'Identifiers of the users to add to the chat.',
					'type' => 'array',
					'items' => [
						'type' => 'integer',
						'minimum' => 1,
					],
					'maxItems' => self::MAX_USERS,
				],
				'hideHistory' => [
					'description' => 'If true, the added users will not see the chat history sent before they joined.',
					'type' => 'boolean',
				],
			],
			'additionalProperties' => false,
			'required' => ['dialogId', 'userIds'],
		];
	}

	/**
	 * @throws McpException
	 */
	protected function executeStructuredTool(int $userId, ...$args): array
	{
		$context = new ImToolContext();

		$dto = AddUsersToChatDto::createFromParams($args);
		$context->validateDto($dto);

		$chat = $context->resolveChatByDialogId($userId, $dto->dialogId);
		$context->assertCanDo($chat, $userId, Action::Extend);

		// The chat<ID> form may resolve to a one-to-one private chat, and a member passes the Extend
		// check there (the private permission matrix does not restrict Extend) while
		// PrivateChat::addUsers() is a silent no-op — without this guard the tool would report
		// result=true having added nobody. Checked after the permission barrier so that non-members
		// get the usual access error and cannot probe chat types by id.
		if ($chat->getType() === Chat::IM_TYPE_PRIVATE)
		{
			throw new McpException(
				'Users cannot be added to a one-to-one private chat. Pass the dialogId of a group chat '
				. '(find it with search_group_chats_by_name), or create a new group chat that includes '
				. 'these users with create_group_chat.',
			);
		}

		$this->assertCanExtendCascadeAncestors($context, $chat, $userId);

		// The schema's maxItems is not enforced by aiassistant's JsonSchemaValidator, so this raw-input
		// check is the actual limit enforcement; it runs before normalization to reject oversized
		// payloads without paying the per-item normalization cost first.
		if (count($dto->userIds) > self::MAX_USERS)
		{
			throw new McpException(
				'Too many users requested. Pass at most ' . self::MAX_USERS
				. ' user IDs per call; split larger lists into several add_users_to_chat calls.',
			);
		}

		$requestedUserIds = Normalizer::toUniquePositiveIntegers($dto->userIds);
		if (empty($requestedUserIds))
		{
			throw new McpException(
				'No valid user IDs found in userIds. Pass positive integer identifiers of the users '
				. 'to add, e.g. "userIds": [123, 456].',
			);
		}

		$chatId = $chat->getChatId();
		$alreadyInChatUserIds = $this->getRelationUserIds($chatId, $requestedUserIds);

		$chat->addUsers(
			$requestedUserIds,
			new AddUsersConfig(hideHistory: $dto->hideHistory, skipAnalytics: false),
		);

		$presentAfterUserIds = $this->getRelationUserIds($chatId, $requestedUserIds);

		$alreadySet = array_fill_keys($alreadyInChatUserIds, true);
		$presentAfterSet = array_fill_keys($presentAfterUserIds, true);

		$addedUserIds = [];
		$notAddedUserIds = [];
		foreach ($requestedUserIds as $requestedUserId)
		{
			if (isset($presentAfterSet[$requestedUserId]))
			{
				if (!isset($alreadySet[$requestedUserId]))
				{
					$addedUserIds[] = $requestedUserId;
				}
			}
			else
			{
				$notAddedUserIds[] = $requestedUserId;
			}
		}

		return [
			'chatId' => $chatId,
			'dialogId' => $chat->getDialogId($userId),
			'requestedUserIds' => $requestedUserIds,
			'alreadyInChatUserIds' => $alreadyInChatUserIds,
			'addedUserIds' => $addedUserIds,
			'notAddedUserIds' => $notAddedUserIds,
			'result' => true,
		];
	}

	/**
	 * Chat::addUsers() with the default AddUsersConfig cascades the add into parent chats
	 * (cascadeToParent) without re-checking the initiator's rights there, while manageUsersAdd
	 * settings of a child and its parent are independent. Walk the same ancestor chain the cascade
	 * will take (hasParent + requiresParentMembership, cycle-guarded like visitedChatIds) and require
	 * Action::Extend on each of those chats, so the tool cannot push users into a more protected ancestor.
	 *
	 * @throws McpException
	 */
	private function assertCanExtendCascadeAncestors(ImToolContext $context, Chat $chat, int $userId): void
	{
		$visitedChatIds = [$chat->getChatId() => true];

		$ancestor = $chat;
		while ($ancestor->hasParent() && $ancestor->requiresParentMembership())
		{
			$parentChatId = $ancestor->getParentChatId();
			if (isset($visitedChatIds[$parentChatId]))
			{
				break;
			}
			$visitedChatIds[$parentChatId] = true;

			// A missing parent resolves to NullChat, whose canDo() is always false — the check fails closed.
			$parent = Chat::getInstance($parentChatId);
			$context->prepareChatContext($parent, $userId);
			if (!$parent->canDo(Action::Extend))
			{
				throw new McpException(
					"Access to action extend is denied in the parent chat \"chat{$parentChatId}\". "
					. 'Adding users to this chat also adds them to its parent chats, and the current user lacks '
					. 'the add-users permission there; ask a manager of that chat to perform this action.',
				);
			}

			$ancestor = $parent;
		}
	}

	/**
	 * Point query of the chat relations limited to the requested user ids.
	 *
	 * Hidden relations are excluded: Chat::addUsers() re-adds such a user by making the relation
	 * visible again, so for the before/after diff a hidden member counts as "not in the chat yet".
	 *
	 * @param int[] $userIds
	 * @return int[]
	 */
	private function getRelationUserIds(int $chatId, array $userIds): array
	{
		if (empty($userIds))
		{
			return [];
		}

		$relations = RelationCollection::find(
			['CHAT_ID' => $chatId, 'USER_ID' => $userIds, 'IS_HIDDEN' => false],
			[],
			null,
			null,
			['ID', 'USER_ID', 'CHAT_ID'],
		);

		return array_values($relations->getUserIds());
	}
}
