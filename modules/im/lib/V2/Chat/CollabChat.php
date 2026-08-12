<?php

namespace Bitrix\Im\V2\Chat;

use Bitrix\Disk\Driver;
use Bitrix\Disk\Folder;
use Bitrix\Im\Access\ChatAuthProvider;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\Collab\CollabInfo;
use Bitrix\Im\V2\Chat\Collab\GuestCounter;
use Bitrix\Im\V2\Entity\User\User;
use Bitrix\Im\V2\Entity\User\UserCollaber;
use Bitrix\Im\V2\Integration\Socialnetwork\Group;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\Permission\Action;
use Bitrix\Im\V2\Permission\ActionGroup;
use Bitrix\Im\V2\Relation\DeleteUserConfig;
use Bitrix\Im\V2\RelationCollection;
use Bitrix\Im\V2\Recent\Initializer;
use Bitrix\Im\V2\Recent\PreviewSource\CollabPreviewSourcePointerService;
use Bitrix\Im\V2\Rest\PopupData;
use Bitrix\Im\V2\Result;
use Bitrix\Main\DI\ServiceLocator;

class CollabChat extends ExternalChat
{
	protected const EXTRANET_CAN_SEE_HISTORY = true;

	private const UNAVAILABLE_ACTION_GROUPS = [
		ActionGroup::ManageUi,
		ActionGroup::ManageSettings,
	];
	private const UNAVAILABLE_ACTIONS = [
		Action::LeaveOwner,
		Action::Update,
		Action::Delete,
	];

	protected function getDefaultType(): string
	{
		return self::IM_TYPE_COLLAB;
	}

	protected function prepareParams(array $params = []): Result
	{
		if (!isset($params['ENTITY_ID']))
		{
			return (new Result())->addError(new ChatError(ChatError::ENTITY_ID_EMPTY));
		}

		$params['ENTITY_TYPE'] = ExtendedType::Sonet->value;

		return parent::prepareParams($params);
	}

	public function getPopupData(array $excludedList = []): PopupData
	{
		return parent::getPopupData($excludedList)->add(new CollabInfo($this));
	}

	/**
	 * Detaching a child chat from the project can leave this collab's recent rows pointing at a message
	 * in a chat that is no longer part of the project, so the materialized preview-source pointer must be
	 * recomputed for every member (the detached child is no longer a resolver candidate).
	 */
	public function onAfterDetachChild(GroupChat $childChat, int $userId): void
	{
		parent::onAfterDetachChild($childChat, $userId);

		ServiceLocator::getInstance()
			->get(CollabPreviewSourcePointerService::class)
			->recomputeForDetachedChild($this)
		;
	}

	public function getStorageId(): int
	{
		return Group::getStorageId((int)$this->getEntityId()) ?? 0;
	}

	protected function createDiskFolder(): ?Folder
	{
		$storage = \CIMDisk::GetStorage($this->chatId);
		if (!$storage)
		{
			return null;
		}

		$folder = $storage->getFolderForUploadedFiles();
		if (!$folder)
		{
			return null;
		}

		$this->setDiskFolderId($folder->getId())->save();
		Driver::getInstance()->getRightsManager()->append($folder, $this->getAccessCodesForDiskFolder());
		$accessProvider = new ChatAuthProvider;
		$accessProvider->updateChatCodesByRelations($this->getId());

		return $folder;
	}

	public function canHaveChild(Chat $child): bool
	{
		return !($child instanceof CommentChat);
	}

	public function isAutoJoinEnabled(): bool
	{
		// Phase 0 (task 718250): enable silent auto-join for closed project chat
		return true;
	}

	public function canDo(Action $action, mixed $target = null): bool
	{
		if (in_array($action, self::UNAVAILABLE_ACTIONS, true))
		{
			return false;
		}

		$actionGroup = ActionGroup::tryFromAction($action);

		if (in_array($actionGroup, self::UNAVAILABLE_ACTION_GROUPS, true))
		{
			return false;
		}

		return parent::canDo($action, $target);
	}

	protected function updateStateAfterRelationsAdd(array $usersToAdd): self
	{
		Initializer::onAfterUsersAddToCollab($usersToAdd, $this->getId());

		if (!empty($this->filterCollabers($usersToAdd)))
		{
			GuestCounter::cleanCache($this->chatId);
		}

		return parent::updateStateAfterRelationsAdd($usersToAdd);
	}

	protected function processUpdateStateAfterUserDelete(int $deletedUserId, DeleteUserConfig $config): self
	{
		if (!empty($this->filterCollabers([$deletedUserId])))
		{
			GuestCounter::cleanCache($this->chatId);
		}

		return parent::processUpdateStateAfterUserDelete($deletedUserId, $config);
	}

	protected function sendPushUsersAdd(array $usersToAdd, RelationCollection $oldRelations): void
	{
		if (!empty($this->filterCollabers($usersToAdd)))
		{
			(new GuestCounter($this))->sendPushGuestCount();
		}

		parent::sendPushUsersAdd($usersToAdd, $oldRelations);
	}

	protected function sendPushUserDelete(int $userId, RelationCollection $oldRelations): void
	{
		if (!empty($this->filterCollabers([$userId])))
		{
			(new GuestCounter($this))->sendPushGuestCount();
		}

		parent::sendPushUserDelete($userId, $oldRelations);
	}

	protected function filterCollabers(array $userIds): array
	{
		return array_filter($userIds, fn (int $userId) => User::getInstance($userId) instanceof UserCollaber);
	}

	protected function updateRecentItems(Message $message): void
	{
		parent::updateRecentItems($message);

		$this->resetPreviewSourceForNotifySubscribed();
	}

	protected function resetPreviewSourceForNotifySubscribed(): void
	{
		if (!\Bitrix\Im\V2\Application\Features::isCollabPreviewSourceEnabled())
		{
			return;
		}

		$notifySubscribedUserIds = $this->getRelations()->filterNotifySubscribed()->getUserIds();
		if (empty($notifySubscribedUserIds))
		{
			return;
		}

		// Hot path: to skip muted members (AC-005) we materialize the non-muted user ids and issue an
		// UPDATE with USER_ID IN (...). On very large collabs that IN list grows; IX_IM_REC_7 (ITEM_CID,
		// USER_ID) covers it. If this needs tuning, replace the materialization with a correlated NOT EXISTS
		// on b_im_relation (NOTIFY_BLOCK).
		\Bitrix\Im\Model\RecentTable::updateByFilter(
			[
				'=ITEM_CID' => $this->getId(),
				'=USER_ID' => array_values($notifySubscribedUserIds),
			],
			[
				'PREVIEW_SOURCE_CID' => 0,
				'PREVIEW_SOURCE_MID' => 0,
			],
		);

		// updateByFilter writes RecentTable directly, so the request-scoped RecentItemCache would keep
		// stale RecentItems for the affected users/chat.
		$cache = ServiceLocator::getInstance()->get(\Bitrix\Im\V2\Recent\Internal\RecentItemCache::class);
		foreach ($notifySubscribedUserIds as $userId)
		{
			$cache->remove((int)$userId, $this->getId());
		}
	}
}
