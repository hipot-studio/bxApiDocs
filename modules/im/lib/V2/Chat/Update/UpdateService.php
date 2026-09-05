<?php

namespace Bitrix\Im\V2\Chat\Update;

use Bitrix\Im\V2\Analytics\ChatAnalytics;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\ChatError;
use Bitrix\Im\V2\Chat\Converter;
use Bitrix\Im\V2\Chat\Copilot\CopilotTitle;
use Bitrix\Im\V2\Chat\CopilotChat;
use Bitrix\Im\V2\Entity\File\ChatAvatar;
use Bitrix\Im\V2\Folder\FolderCascadeHandler;
use Bitrix\Im\V2\Guest\GuestLinkService;
use Bitrix\Im\V2\Integration\HumanResources\Structure;
use Bitrix\Im\V2\Recent\AncestorContextSender;
use Bitrix\Im\V2\Relation;
use Bitrix\Im\V2\Relation\AddUsersConfig;
use Bitrix\Im\V2\Relation\DeleteUserConfig;
use Bitrix\Im\V2\Relation\Reason;
use Bitrix\Im\V2\Result;
use Bitrix\Main\DI\ServiceLocator;

class UpdateService
{
	protected UpdateFields $updateFields;
	protected Chat\GroupChat $chat;
	protected ?string $newChatType = null;

	public function __construct(Chat\GroupChat $chat, UpdateFields $updateFields)
	{
		$this->chat = $chat;
		$this->updateFields  = $updateFields;
	}

	public function updateChat(): Result
	{
		$prevAnalyticsData = $this->getAnalyticsData();

		$validateParentResult = $this->validateParent();
		if (!$validateParentResult->isSuccess())
		{
			return $validateParentResult;
		}

		$convertResult = $this->convertChat();

		if (!$convertResult->isSuccess())
		{
			return $convertResult;
		}

		$this->updateAvatarBeforeSave();

		// captured before save: on detach the new PARENT_ID is 0 and the old parent chain is lost afterwards
		$oldParentChatId = (int)($this->chat->getParentChatId() ?? 0);
		$membersToPromoteOnAttach = $this->captureMembersToPromoteOnAttach($oldParentChatId);

		$this->chat->fill($this->getArrayToSave());
		$result = $this->chat->save();

		if (!$result->isSuccess())
		{
			return $result->setResult($this->chat);
		}

		$svc = $this
			->sendMessageAfterUpdateAvatar()
			->deleteUsers()
			->addUsers()
			->deleteManagers()
			->addManagers()
		;

		ChatAnalytics::blockSingleUserEvents($this->chat);

		$svc
			->deleteDepartments()
			->addDepartments()
		;

		// A successful update through the general chat-update path counts as the
		// first user interaction with a copilot draft (e.g. a description-only
		// change, which has no dedicated activation point): turn it into a real
		// chat so it stops being hidden. Run after membership/department changes
		// so the search index is built once with the final roster instead of
		// being created early and then rewritten. No-op for non-draft/non-copilot chats.
		CopilotChat::activateDraftIfNeeded($this->chat);

		$this->promoteMembersToParentOnAttach($membersToPromoteOnAttach);

		// On attach (root -> parent) a nested chat must leave every personal folder:
		// personal folders keep root chats only.
		$newParentChatId = (int)($this->updateFields->getParentChatId() ?? 0);
		if ($oldParentChatId === 0 && $newParentChatId > 0)
		{
			ServiceLocator::getInstance()->get(FolderCascadeHandler::class)
				->onChatAttachedToParent($this->chat->getChatId())
			;
		}

		$this->sendPushUpdateChat($oldParentChatId);
		$this->markCopilotTitleAsCustom();
		$this->compareAnalyticsData($prevAnalyticsData);
		$this->revokeStaleGuestLinks($prevAnalyticsData);

		ChatAnalytics::unblockSingleUserEventsByChat($this->chat);

		return $result->setResult($this->chat);
	}

	private function revokeStaleGuestLinks(array $prevAnalyticsData): void
	{
		$oldRights = $prevAnalyticsData['manageGuestInvites'] ?? null;
		$newRights = $this->chat->getManageGuestInvites();

		if ($oldRights === null || $newRights === null || $oldRights === $newRights)
		{
			return;
		}

		GuestLinkService::getInstance()
			->onManageGuestInvitesChanged($this->chat, $oldRights, $newRights)
		;
	}

	/**
	 * Integrity validation of the parent change only (tree consistency).
	 * Business rules of attach/detach (rights, transitions, departments) live in ChatTreeAttachmentManager.
	 * parentChatId === null means the parent is not changed; 0 means detach to root.
	 */
	protected function validateParent(): Result
	{
		$result = new Result();
		$parentChatId = $this->updateFields->getParentChatId();

		if ($parentChatId === null || $parentChatId === 0)
		{
			return $result;
		}

		if ($parentChatId === $this->chat->getChatId())
		{
			return $result->addError(new ChatError(ChatError::WRONG_PARENT_CHAT));
		}

		$parentChat = Chat::getInstance($parentChatId)->withContext($this->chat->getContext());
		if ($parentChat->getChatId() <= 0)
		{
			return $result->addError(new ChatError(ChatError::WRONG_PARENT_CHAT));
		}

		if (!$parentChat->canHaveChild($this->chat))
		{
			return $result->addError(new ChatError(ChatError::PARENT_CAN_NOT_HAVE_CHILD));
		}

		return $result;
	}

	protected function convertChat(): Result
	{
		$result = new Result();

		$newType = $this->getConvertType();

		if (!isset($newType) || $this->chat->getType() === $newType)
		{
			return $result;
		}

		$convertResult = (new Converter($this->chat->getId(), $newType))->convert();

		if (!$convertResult->isSuccess())
		{
			return $result->addErrors($convertResult->getErrors());
		}

		$this->newChatType = $newType;

		// replace object after conversion
		$this->chat = Chat\GroupChat::getInstance($this->chat->getChatId());

		return $result;
	}

	protected function getConvertType(): ?string
	{
		$searchable = $this->updateFields->getSearchable();
		$currentType = $this->chat->getType();
		$newType = $this->updateFields->getType();

		return match (true)
		{
			$currentType === Chat::IM_TYPE_CHAT && $searchable === 'Y' => \Bitrix\Im\V2\Chat::IM_TYPE_OPEN,
			$currentType === Chat::IM_TYPE_OPEN && $searchable === 'N' => \Bitrix\Im\V2\Chat::IM_TYPE_CHAT,
			$currentType === Chat::IM_TYPE_CHANNEL && $searchable === 'Y' => \Bitrix\Im\V2\Chat::IM_TYPE_OPEN_CHANNEL,
			$currentType === Chat::IM_TYPE_OPEN_CHANNEL && $searchable === 'N' => \Bitrix\Im\V2\Chat::IM_TYPE_CHANNEL,
			$currentType === Chat::IM_TYPE_COLLAB && $searchable === 'Y' => \Bitrix\Im\V2\Chat::IM_TYPE_OPEN_COLLAB,
			$currentType === Chat::IM_TYPE_OPEN_COLLAB && $searchable === 'N' => \Bitrix\Im\V2\Chat::IM_TYPE_COLLAB,
			default => $newType,
		};

	}

	protected function addUsers(): self
	{
		$updateFields = $this->updateFields;

		$ownerId = $updateFields->getOwnerId();
		$addedUsers = array_unique(array_merge(
			$updateFields->getAddedUsers(),
			$updateFields->getAddedManagers(),
			$ownerId !== null ? [$ownerId] : [],
		));

		if (empty($addedUsers))
		{
			return $this;
		}

		$config = new AddUsersConfig(
			managerIds: $updateFields->getAddedManagers(),
			hideHistory: $updateFields->shouldHideHistory(),
			skipAnalytics: false
		);
		$this->chat->addUsers($addedUsers, $config);

		return $this;
	}

	protected function deleteUsers(): self
	{
		$deletedUsers = $this->updateFields->getDeletedUsers();

		foreach ($deletedUsers as $userId)
		{
			$this->chat->deleteUser((int)$userId, new DeleteUserConfig(false));
		}

		return $this;
	}

	protected function addManagers(): self
	{
		$addManagers = $this->updateFields->getAddedManagers();

		if (empty($addManagers))
		{
			return $this;
		}

		$this->chat->addManagers($addManagers, false);

		return $this;
	}

	protected function deleteManagers(): self
	{
		$deleteManagers = $this->updateFields->getDeletedManagers();

		if (empty($deleteManagers))
		{
			return $this;
		}

		$this->chat->deleteManagers($deleteManagers, false);

		return $this;
	}

	protected function addDepartments(): self
	{
		$addNodes = $this->updateFields->getAddedDepartments();

		if (empty($addNodes))
		{
			return $this;
		}

		(new Structure($this->chat))->link($addNodes);

		foreach ($addNodes as $node)
		{
			(new ChatAnalytics($this->chat))->addAddDepartment();
		}

		return $this;
	}

	protected function deleteDepartments(): self
	{
		$deleteNodes = $this->updateFields->getDeletedDepartments();

		if (empty($deleteNodes))
		{
			return $this;
		}

		$currentNodes = (new Structure($this->chat))->getNodesAccessCodes();

		foreach ($deleteNodes as $key => $node)
		{
			if (!in_array($node, $currentNodes, true))
			{
				unset($deleteNodes[$key]);
			}
		}

		(new Structure($this->chat))->unlink($deleteNodes);

		foreach ($deleteNodes as $node)
		{
			(new ChatAnalytics($this->chat))->addDeleteDepartment();
		}

		return $this;
	}

	protected function updateAvatarBeforeSave(): self
	{
		$avatar = $this->updateFields->getAvatar();
		if (!is_int($avatar) && !is_string($avatar))
		{
			return $this;
		}

		(new ChatAvatar($this->chat))->update($avatar, false, false, true);

		return $this;
	}

	protected function sendMessageAfterUpdateAvatar(): self
	{
		$avatar = $this->updateFields->getAvatar();
		if (!is_int($avatar) && !is_string($avatar))
		{
			return $this;
		}

		$this->chat->sendMessageUpdateAvatar();

		return $this;
	}

	protected function sendPushUpdateChat(int $oldParentChatId = 0): void
	{
		if (!\Bitrix\Main\Loader::includeModule("pull"))
		{
			return;
		}

		$activeUserIds = $this->chat->getRelations()->filterActiveMembers()->getUserIds();
		$newParentChatId = $this->updateFields->getParentChatId();

		// The chat ITSELF is not sent a recentUpdate: chatUpdate below carries the new parentChatId and the
		// client repositions the recent item between sections from it. Only the parent chain gets recent
		// context here.
		//
		// Ancestor recent context must reach the client BEFORE chatUpdate: it pre-fills parent-chain metadata
		// (info-only). On detach the chat no longer points at its former parent, so walk the FORMER parent
		// chain (including the old parent) — its preview/last-activity must recompute without this child.
		$ancestorSender = ServiceLocator::getInstance()->get(AncestorContextSender::class);
		if ($newParentChatId === 0 && $oldParentChatId > 0)
		{
			$ancestorSender->sendForChainIncluding(Chat::getInstance($oldParentChatId), $activeUserIds);
		}
		else
		{
			$ancestorSender->send($this->chat, $activeUserIds);
		}

		$pushMessage = [
			'module_id' => 'im',
			'command' => 'chatUpdate',
			'expiry' => 3600,
			'params' => [
				'chat' => $this->chat->toPullFormat(),
			],
			'extra' => \Bitrix\Im\Common::getPullExtra()
		];

		\Bitrix\Pull\Event::add($this->chat->getRelations()->getUserIds(), $pushMessage);
		if ($this->chat->needToSendPublicPull())
		{
			\CPullWatch::AddToStack('IM_PUBLIC_' . $this->chat->getId(), $pushMessage);
		}
	}

	/**
	 * On attach (parent 0 -> N) the chat's members must become members of the parent project. We read
	 * them while the chat is still parentless so getRelations() is unfiltered — reading the access-filtered
	 * view after the parent is set would both omit and mark-for-deletion members who are not project
	 * members yet. Returns [] for any update that is not an attach.
	 *
	 * @return int[]
	 */
	private function captureMembersToPromoteOnAttach(int $oldParentChatId): array
	{
		$newParentChatId = $this->updateFields->getParentChatId();
		$isAttach = $newParentChatId !== null && $newParentChatId > 0 && $oldParentChatId === 0;
		if (!$isAttach)
		{
			return [];
		}

		return $this->chat->getRelations()
			->filterActiveMembers()
			->filter(static fn (Relation $relation): bool => $relation->getReason() === Reason::DEFAULT)
			->getUserIds()
		;
	}

	private function promoteMembersToParentOnAttach(array $userIds): void
	{
		if (empty($userIds))
		{
			return;
		}

		Chat::getInstance((int)$this->updateFields->getParentChatId())
			->withContext($this->chat->getContext())
			->addUsers($userIds, new AddUsersConfig())
		;
	}

	protected function markCopilotTitleAsCustom(): void
	{
		if ($this->updateFields->getTitle() !== null && $this->chat instanceof CopilotChat)
		{
			(new CopilotTitle($this->chat->getChatId()))->markAsCustom();
		}
	}

	protected function getArrayToSave(): array
	{
		$fields = $this->filterFieldsByDifference($this->updateFields->getArrayToSave());

		if (isset($this->newChatType))
		{
			$fields['TYPE'] = $this->newChatType;
		}

		return $fields;
	}

	protected function filterFieldsByDifference(array $fields): array
	{
		if ($this->chat->getDescription() === $fields['DESCRIPTION'])
		{
			unset($fields['DESCRIPTION']);
		}

		return $fields;
	}

	protected function compareAnalyticsData(array $prevData): void
	{
		$currentData = $this->getAnalyticsData();
		$analytics = new ChatAnalytics($this->chat);
		$diff = fn(string $key) => $currentData[$key] !== $prevData[$key];

		if ($diff('description'))
		{
			$analytics->addEditDescription();
		}

		if ($diff('type'))
		{
			$analytics->addSetType();
		}

		if (
			$diff('owner') ||
			$diff('manageUI') ||
			$diff('manageUsersAdd') ||
			$diff('manageUsersDelete') ||
			$diff('manageMessages') ||
			$diff('manageGuestInvites')
		)
		{
			$analytics->addEditPermissions();
		}
	}

	protected function getAnalyticsData(): array
	{
		return [
			'description' => $this->chat->getDescription(),
			'type' => $this->chat->getType(),
			'owner' => $this->chat->getAuthorId(),
			'manageUI' => $this->chat->getManageUI(),
			'manageUsersAdd' => $this->chat->getManageUsersAdd(),
			'manageUsersDelete' => $this->chat->getManageUsersDelete(),
			'manageMessages' => $this->chat->getManageMessages(),
			'manageGuestInvites' => $this->chat->getManageGuestInvites(),
		];
	}
}
