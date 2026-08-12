<?php

namespace Bitrix\Im\V2\Chat\Tree;

use Bitrix\Im\Model\MessageUnreadTable;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\ChatError;
use Bitrix\Im\V2\Chat\GroupChat;
use Bitrix\Im\V2\Chat\Update\UpdateFields;
use Bitrix\Im\V2\Chat\Update\UpdateService;
use Bitrix\Im\V2\Permission\Action;
use Bitrix\Im\V2\Reading\Counter\Internal\CountersCache;
use Bitrix\Im\V2\Result;
use Bitrix\Main\Application;

/**
 * UpdateService performs the bare PARENT_ID change; everything around it — rights, transitions and the
 * member cascade — lives here. Re-parenting (N -> M) is forbidden: an unattached chat can be attached
 * (0 -> N), an attached chat can be detached (N -> 0).
 */
class ChatTreeAttachmentManager
{
	public function __construct(
		private readonly CountersCache $countersCache,
		private readonly ChatTreeDepartmentSynchronizer $departmentSynchronizer,
	)
	{
	}

	public function attachToParent(GroupChat $chat, int $parentChatId, int $userId): Result
	{
		$validateResult = $this->validateAttach($chat, $parentChatId);
		if (!$validateResult->isSuccess())
		{
			return $validateResult;
		}

		$parentChat = Chat::getInstance($parentChatId)->withContext($chat->getContext());
		$beforeResult = $parentChat->onBeforeAttachChild($chat, $userId);
		if (!$beforeResult->isSuccess())
		{
			return $beforeResult;
		}

		// UpdateService promotes the chat's members into the parent project as part of the parent change —
		// before its access-filtered push — so members not yet in the project are not dropped (mantis 249876).
		$result = (new UpdateService($chat, new UpdateFields(parentChatId: $parentChatId)))->updateChat();
		if (!$result->isSuccess())
		{
			return $result;
		}

		// reload to get the actual parent state after save
		$chat = GroupChat::getInstance($chat->getChatId());

		$this->departmentSynchronizer->syncToAncestors($chat);
		$this->syncUnreadTree($chat, $parentChatId);
		$parentChat->onAfterAttachChild($chat, $userId);

		return new Result();
	}

	public function detachFromParent(GroupChat $chat, int $userId): Result
	{
		$validateResult = $this->validateDetach($chat);
		if (!$validateResult->isSuccess())
		{
			return $validateResult;
		}

		// capture the parent before it is cleared — afterwards getParentChat() is null
		$oldParentChat = $chat->getParentChat();
		if ($oldParentChat !== null)
		{
			$beforeResult = $oldParentChat->onBeforeDetachChild($chat, $userId);
			if (!$beforeResult->isSuccess())
			{
				return $beforeResult;
			}
		}

		$result = (new UpdateService($chat, new UpdateFields(parentChatId: 0)))->updateChat();
		if (!$result->isSuccess())
		{
			return $result;
		}

		$chat = GroupChat::getInstance($chat->getChatId());
		$this->syncUnreadTree($chat, 0);
		$oldParentChat?->onAfterDetachChild($chat, $userId);

		return $result;
	}

	protected function validateAttach(GroupChat $chat, int $parentChatId): Result
	{
		$result = new Result();

		if ($chat->hasParent())
		{
			return $result->addError(new ChatError(ChatError::CHAT_ALREADY_HAS_PARENT));
		}

		if ($parentChatId <= 0 || $parentChatId === $chat->getChatId())
		{
			return $result->addError(new ChatError(ChatError::WRONG_PARENT_CHAT));
		}

		if (!$chat->canDo(Action::AttachToParent))
		{
			return $result->addError(new ChatError(ChatError::ACCESS_DENIED));
		}

		$parentChat = Chat::getInstance($parentChatId)->withContext($chat->getContext());
		if ($parentChat->getChatId() <= 0)
		{
			return $result->addError(new ChatError(ChatError::WRONG_PARENT_CHAT));
		}

		if (!$parentChat->checkAccess()->isSuccess())
		{
			return $result->addError(new ChatError(ChatError::ACCESS_DENIED));
		}

		if (!$parentChat->canDo(Action::CreateChildChat))
		{
			return $result->addError(new ChatError(ChatError::ACCESS_DENIED));
		}

		// invite right on the target project: attaching force-adds the chat's members into the project,
		// so the actor must be allowed to add participants there (Action::Extend resolves to the project's
		// manageUsersAdd setting via DefaultPolicy)
		if (!$parentChat->canDo(Action::Extend))
		{
			return $result->addError(new ChatError(ChatError::ACCESS_DENIED));
		}

		// canHaveChild (tree integrity) is validated by UpdateService::validateParent

		return $result;
	}

	protected function validateDetach(GroupChat $chat): Result
	{
		$result = new Result();

		if (!$chat->hasParent())
		{
			return $result->addError(new ChatError(ChatError::CHAT_HAS_NO_PARENT));
		}

		if (!$chat->canDo(Action::DetachFromParent))
		{
			return $result->addError(new ChatError(ChatError::ACCESS_DENIED));
		}

		return $result;
	}

	/**
	 * Keeps the unread-counters tree denormalization (b_im_message_unread.PARENT_ID) in step with the
	 * chat tree and drops the affected users' counters cache. Both run in one terminate-phase job — off
	 * the request path, and ordered so the cache is cleared only AFTER the denormalization; otherwise the
	 * next recompute would read the stale PARENT_ID and re-cache it for the whole TTL.
	 *
	 * The client-facing pulls are not part of this deferred job: UpdateService emits recentUpdate (the new
	 * parent context) right before chatUpdate, so the client applies the context first and re-renders on
	 * chatUpdate.
	 */
	protected function syncUnreadTree(GroupChat $chat, int $parentChatId): void
	{
		$chatId = $chat->getChatId();
		if ($chatId <= 0)
		{
			return;
		}

		$userIds = $chat->getRelations()->getUserIds();
		$countersCache = $this->countersCache;

		Application::getInstance()->addBackgroundJob(
			static function () use ($chatId, $parentChatId, $userIds, $countersCache): void {
				MessageUnreadTable::updateByFilter(
					['=CHAT_ID' => $chatId],
					['PARENT_ID' => $parentChatId],
				);

				foreach ($userIds as $userId)
				{
					$countersCache->remove((int)$userId);
				}
			}
		);
	}

}
