<?php

namespace Bitrix\Im\V2\Sync;

use Bitrix\Im\Integration\Socialnetwork\Extranet;
use Bitrix\Im\V2\Entity\User\User;
use Bitrix\Im\V2\Entity\User\UserPopupItem;
use Bitrix\Im\V2\MessageCollection;
use Bitrix\Im\V2\Recent\Config\ChatRecentConfigs;
use Bitrix\Im\V2\Rest\PopupData;
use Bitrix\Im\V2\Rest\RestAdapter;
use Bitrix\Im\V2\Sync\Entity\Chats;
use Bitrix\Im\V2\Sync\Entity\DialogIds;
use Bitrix\Im\V2\Sync\Entity\Messages;
use Bitrix\Im\V2\Sync\Entity\PinMessages;
use Bitrix\Im\V2\Sync\Entity\Users;
use Bitrix\Im\V2\Chat\Comment\CommentPopupItem;
use Bitrix\Im\V2\TariffLimit\TariffLimitPopupItem;

class Entities
{
	protected array $rest = [];

	public function __construct(
		protected Chats $chats,
		protected Messages $messages,
		protected PinMessages $pinMessages,
		protected DialogIds $dialogIds,
		protected Users $users = new Users(),
		protected int $currentUserId = 0,
	)
	{}

	public function getRestData(bool $withCounters = false): array
	{
		$this->applyUserVisibilityGate();
		$this->loadRestData($withCounters);
		$this->rest['dialogIds'] = $this->fillRestDialogData();

		return $this->rest;
	}

	/**
	 * RF-2: gate updated (fired/restored) user ids by requester visibility — the
	 * profile popup is the PII channel. intranet sees all, extranet/guest only
	 * accessible ids; filterActiveUser=false keeps fired (ACTIVE='N') colleagues.
	 * deletedUsers are NOT gated: bare ids without profile, and the deleted user's
	 * group membership is already wiped on delete, so gating would only drop the
	 * delete delta for extranet.
	 */
	protected function applyUserVisibilityGate(): void
	{
		$updatedUserIds = array_values($this->users->getUpdatedUserIds());

		if (empty($updatedUserIds))
		{
			return;
		}

		$this->users->setUpdatedUserIds($this->filterVisibleUserIds($updatedUserIds));
	}

	/**
	 * @param int[] $userIds
	 * @return int[]
	 */
	protected function filterVisibleUserIds(array $userIds): array
	{
		if (empty($userIds))
		{
			return [];
		}

		// fail-closed: no requester context => leak nothing.
		if ($this->currentUserId <= 0)
		{
			return [];
		}

		$requester = User::getInstance($this->currentUserId);

		// Guests are visible-by-common-chat, not by extranet groups, and
		// Extranet::filterUserList misclassifies them. Route guests through the
		// canonical per-type checkAccess (UserGuest => hasCommonChat); everyone else
		// keeps the batched filterUserList path (one cached getGroup), preserving the
		// pre-existing intranet/extranet behavior. filterActiveUser=false keeps fired
		// (ACTIVE='N') colleagues visible.
		if ($requester->isGuest())
		{
			return array_values(array_filter(
				$userIds,
				static fn ($id) => $requester->checkAccess((int)$id)->isSuccess()
			));
		}

		$filtered = Extranet::filterUserList($userIds, $this->currentUserId, false);

		// false (invalid requester) => [] (fail-closed)
		return is_array($filtered) ? array_values($filtered) : [];
	}

	protected function loadRestData(bool $withCounters = false): void
	{
		$this->rest[Chats::getRestEntityName()] = $this->chats->toRestFormat();
		$this->rest[Messages::getRestEntityName()] = $this->messages->toRestFormat();
		$this->rest[PinMessages::getRestEntityName()] = $this->pinMessages->toRestFormat();
		$this->rest[Users::getRestEntityName()] = $this->users->toRestFormat();
		$this->rest[ChatRecentConfigs::getRestEntityName()] = $this->chats->getRecentConfigs()->toRestFormat();

		$messageCollection = $this->getMessageCollection();
		$pinCollection = $this->pinMessages->getPinCollection();
		$chatItems = $this->chats->getChatItems($withCounters);

		$adapter = new RestAdapter($messageCollection, $pinCollection, $chatItems);

		$updatedUserIds = $this->users->getUpdatedUserIds();
		if (!empty($updatedUserIds))
		{
			$adapter->addAdditionalPopupData(new PopupData([new UserPopupItem(array_values($updatedUserIds))]));
		}

		$restData = $adapter->toRestFormat($this->getOption());
		$this->rest = array_merge($this->rest, $restData);
	}

	protected function getOption(): array
	{
		return [
			'POPUP_DATA_EXCLUDE' => [CommentPopupItem::class, TariffLimitPopupItem::class]
		];
	}

	protected function getMessageCollection(): MessageCollection
	{
		$messageIds = $this->messages->getMessageIds() + $this->chats->getMessageIds();

		return (new MessageCollection($messageIds));
	}

	protected function fillRestDialogData(): array
	{
		return $this->dialogIds->load($this->rest)->toRestFormat();
	}
}
