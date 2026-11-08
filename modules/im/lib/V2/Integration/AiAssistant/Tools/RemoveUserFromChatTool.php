<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\AiAssistant\Tools;

use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\Im\V2\Entity\User\UserError;
use Bitrix\Im\V2\Integration\AiAssistant\Tools\Dto\RemoveUserFromChatDto;
use Bitrix\Im\V2\Integration\AiAssistant\Tools\Support\ImToolContext;
use Bitrix\Im\V2\Integration\AiAssistant\ToolScope;
use Bitrix\Im\V2\Integration\HumanResources\Structure;
use Bitrix\Im\V2\Permission\Action;
use Bitrix\Im\V2\Relation\DeleteUserConfig;
use Bitrix\Im\V2\RelationCollection;

class RemoveUserFromChatTool extends BaseImTool
{
	protected function getScope(): ToolScope
	{
		return ToolScope::Write;
	}

	public function getName(): string
	{
		return 'remove_user_from_chat';
	}

	public function getDescription(): string
	{
		return "Removes a single user, identified by targetUserId, from the group chat identified by dialogId.
			Removing yourself makes you leave the chat, and removing the chat owner automatically transfers ownership; both of these irreversible cases are rejected unless confirmSelfLeaveOrOwnerChange is set to true. Removing an ordinary member does not require that flag.
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
				'targetUserId' => [
					'description' => 'Identifier of the user to remove from the chat.',
					'type' => 'integer',
					'minimum' => 1,
				],
				'confirmSelfLeaveOrOwnerChange' => [
					'description' => 'Must be set to true to confirm the irreversible effect when removing yourself (leaving the chat) or removing the chat owner (ownership is transferred automatically).',
					'type' => 'boolean',
					'default' => false,
				],
			],
			'additionalProperties' => false,
			'required' => ['dialogId', 'targetUserId'],
		];
	}

	/**
	 * @throws McpException
	 */
	protected function executeStructuredTool(int $userId, ...$args): array
	{
		$context = new ImToolContext();

		$dto = RemoveUserFromChatDto::createFromParams($args);
		$context->validateDto($dto);

		$targetUserId = $dto->targetUserId;

		$chat = $context->resolveChatByDialogId($userId, $dto->dialogId);
		$context->assertCanDo($chat, $userId, Action::Kick, $targetUserId);

		// Determined immediately before deleteUser() to keep the self/owner detection window as small as possible.
		// deleteUser() re-evaluates ownership internally and does not expose an "owner changed" flag in its Result,
		// so there is no cheap way to fully close the window without a lock or transaction; the rare race is accepted.
		if (!$dto->confirmSelfLeaveOrOwnerChange && $this->isIrreversibleRemoval($chat, $userId, $targetUserId))
		{
			throw new McpException(
				'Removing this user is irreversible: removing yourself leaves the chat and removing the owner transfers ownership. '
				. 'Set confirmSelfLeaveOrOwnerChange to true to proceed.',
			);
		}

		$result = $chat->deleteUser($targetUserId, new DeleteUserConfig());
		if (!$result->isSuccess())
		{
			throw new McpException($this->mapDeleteError($result->getErrors()));
		}

		// A successful Result does not guarantee the relation is gone: ExternalChat::deleteUser() (collab,
		// workgroup, entity chats) returns one even when a BeforeUsersDeleteEvent handler cancels or
		// filters out the deletion, and a deletion that did happen can be undone by the chat itself.
		if ($this->isChatMember($chat->getChatId(), $targetUserId))
		{
			throw new McpException($this->explainRemainingMembership($chat, $targetUserId));
		}

		return [
			'chatId' => $chat->getChatId(),
			'dialogId' => $chat->getDialogId($userId),
			'removedUserId' => $targetUserId,
			'result' => true,
		];
	}

	/**
	 * A member still in the chat has two causes now, and they call for opposite advice: the chat type
	 * can refuse the change, or the deletion went through and the check that follows it put a member the
	 * company structure holds back. Naming the first cause for the second one sends the user to a
	 * manager of an entity that refused nothing.
	 */
	private function explainRemainingMembership(\Bitrix\Im\V2\Chat $chat, int $targetUserId): string
	{
		if ((new Structure($chat))->holdsUser($targetUserId) === true)
		{
			return 'Unable to remove the user from the chat: they belong to a company structure entity linked '
				. 'to this chat, and the chat keeps its membership in step with that entity. '
				. 'Remove them from that department or team, or unlink it from the chat, to remove them here.';
		}

		return 'Unable to remove the user from the chat: the chat integration rejected this membership change. '
			. 'For example, in a collab the project access rules may forbid excluding this member; '
			. 'ask a manager of that entity to perform this action.';
	}

	private function isIrreversibleRemoval(\Bitrix\Im\V2\Chat $chat, int $userId, int $targetUserId): bool
	{
		return $targetUserId === $userId || $targetUserId === $chat->getAuthorId();
	}

	/**
	 * Point query of the actual relation row, bypassing in-memory chat state. Hidden relations count
	 * as membership: deleteUser() removes them too, so any remaining row means the removal did not happen.
	 */
	private function isChatMember(int $chatId, int $targetUserId): bool
	{
		$relations = RelationCollection::find(
			['CHAT_ID' => $chatId, 'USER_ID' => [$targetUserId]],
			[],
			1,
			null,
			['ID', 'USER_ID', 'CHAT_ID'],
		);

		return !$relations->isEmpty();
	}

	/**
	 * @param \Bitrix\Main\Error[] $errors
	 */
	private function mapDeleteError(array $errors): string
	{
		foreach ($errors as $error)
		{
			switch ($error->getCode())
			{
				case UserError::NOT_FOUND:
					return 'The specified user is not a member of this chat. '
						. 'Check the current member list with get_chat_members and verify targetUserId.';
				case UserError::DELETE_FROM_STRUCTURE_SYNC:
					return 'This user was added through the company structure and cannot be removed from the chat manually.';
			}
		}

		return 'Unable to remove the user from the chat. Make sure the chat still exists, targetUserId is a current '
			. 'member and you have the right to remove members, then try again.';
	}
}
