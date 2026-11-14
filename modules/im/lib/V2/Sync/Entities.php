<?php

namespace Bitrix\Im\V2\Sync;

use Bitrix\Im\Integration\Socialnetwork\Extranet;
use Bitrix\Im\Model\ChatParamTable;
use Bitrix\Im\V2\Chat\Param\Params;
use Bitrix\Im\V2\Entity\User\User;
use Bitrix\Im\V2\Entity\User\UserPopupItem;
use Bitrix\Im\V2\Link\Pin\PinCollection;
use Bitrix\Im\V2\Link\Pin\PinItem;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\MessageCollection;
use Bitrix\Im\V2\Recent\Config\ChatRecentConfigs;
use Bitrix\Im\V2\RelationCollection;
use Bitrix\Im\V2\Rest\PopupData;
use Bitrix\Im\V2\Rest\RestAdapter;
use Bitrix\Im\V2\Sync\Entity\Chats;
use Bitrix\Im\V2\Sync\Entity\DialogIds;
use Bitrix\Im\V2\Sync\Entity\Messages;
use Bitrix\Im\V2\Sync\Entity\PersonalPinMessages;
use Bitrix\Im\V2\Sync\Entity\PinMessages;
use Bitrix\Im\V2\Sync\Entity\Users;
use Bitrix\Im\V2\TariffLimit\TariffLimitPopupItem;

class Entities
{
	protected array $rest = [];

	/** Chats a single border selection asks about, kept below the id-list limits of the drivers. */
	private const BORDER_PREFETCH_CHUNK = 300;

	/** @var array<int, int> history border per chat, shared by the message and the pin cut */
	private array $startIds = [];

	/** @var array<int, int> HISTORY_START_ID per chat, prefetched; a chat without a cleanup is kept as 0 */
	private array $historyBorders = [];

	public function __construct(
		protected Chats $chats,
		protected Messages $messages,
		protected PinMessages $pinMessages,
		protected DialogIds $dialogIds,
		protected Users $users = new Users(),
		protected int $currentUserId = 0,
		protected PersonalPinMessages $personalPinMessages = new PersonalPinMessages(),
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
		$this->chats->setHiddenHistoryBorders($this->getHiddenHistoryBorders());
		$this->rest[Chats::getRestEntityName()] = $this->chats->toRestFormat();
		$this->rest[Users::getRestEntityName()] = $this->users->toRestFormat();
		$this->rest[ChatRecentConfigs::getRestEntityName()] = $this->chats->getRecentConfigs()->toRestFormat();

		$messageCollection = $this->getMessageCollection();
		$this->rest[Messages::getRestEntityName()] = $this->messages->toRestFormat();

		$pinCollection = $this->getPinCollection();
		$this->rest[PinMessages::getRestEntityName()] = $this->pinMessages->toRestFormat();

		$personalPinCollection = $this->personalPinMessages->getPinCollection($this->currentUserId);
		$this->rest[PersonalPinMessages::getRestEntityName()] = $this->personalPinMessages->toRestFormat();

		$chatItems = $this->chats->getChatItems($withCounters);

		$adapter = new RestAdapter($messageCollection, $pinCollection, $chatItems, $personalPinCollection);

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
			'POPUP_DATA_EXCLUDE' => [TariffLimitPopupItem::class]
		];
	}

	/**
	 * The sync window tells nothing about what the requester may read: a cleanup announced in an
	 * earlier window still hides everything below the border, while messages written after the
	 * cleanup stay readable even in the very window that carries the cleanup event. So the cut is
	 * the START_ID of the user the answer is built for, the same border getPinCollection() applies;
	 * hiddenHistoryChats stays a marker for the client, not a filtering criterion.
	 *
	 * An id the window announces and the collection does not carry belongs to a message that is gone:
	 * the collection is loaded by ids alone, and the "add" row of the journal outlives the message it
	 * announced - a scheduled cleanup takes the message away without an event of its own. Such an id
	 * leaves the answer as well, otherwise the client is told to add a message nothing can deliver.
	 */
	protected function getMessageCollection(): MessageCollection
	{
		$messageIds = $this->messages->getMessageIds() + $this->chats->getMessageIds();
		$messageCollection = new MessageCollection($messageIds);
		$this->primeStartIds($messageCollection->getChatIds());

		$excludedIds = [];
		$loadedIds = [];

		foreach ($messageCollection as $message)
		{
			$messageId = (int)$message->getId();
			$loadedIds[$messageId] = $messageId;
			$chatId = (int)$message->getChatId();
			$startId = $this->getStartId($chatId);

			if ($startId > 0 && $messageId < $startId)
			{
				$excludedIds[$messageId] = $messageId;
			}
		}

		foreach ($messageIds as $messageId)
		{
			$messageId = (int)$messageId;

			if (!isset($loadedIds[$messageId]))
			{
				$excludedIds[$messageId] = $messageId;
			}
		}

		if (empty($excludedIds))
		{
			return $messageCollection;
		}

		$this->messages->excludeMessageIds($excludedIds);

		return $messageCollection->filter(
			static fn (Message $message) => !isset($excludedIds[$message->getId()])
		);
	}

	/**
	 * A pin carries the pinned message body along with it (AdditionalMessagePopupItem), so the
	 * history border has to cut the pin set here the same way the sidebar and chat load surfaces
	 * cut it through the START_ID filter of PinCollection::find(). Excluded ids also leave
	 * addedPins, otherwise the client keeps a pin pointing at a message it can no longer load.
	 */
	protected function getPinCollection(): PinCollection
	{
		$pinCollection = $this->pinMessages->getPinCollection();
		$chatIds = [];

		foreach ($pinCollection as $pin)
		{
			$chatIds[] = (int)$pin->getChatId();
		}

		$this->primeStartIds($chatIds);

		$excludedIds = [];

		foreach ($pinCollection as $pin)
		{
			$startId = $this->getStartId((int)$pin->getChatId());

			if ($startId > 0 && (int)$pin->getMessageId() < $startId)
			{
				$excludedIds[$pin->getPrimaryId()] = $pin->getPrimaryId();
			}
		}

		if (empty($excludedIds))
		{
			return $pinCollection;
		}

		$this->pinMessages->excludePinIds($excludedIds);

		return $pinCollection->filter(static fn (PinItem $pin) => !isset($excludedIds[$pin->getPrimaryId()]));
	}

	/**
	 * The exact cut this answer was made with: the last message hidden from the requester in every chat
	 * the window announces as cleared. It is the same START_ID the messages and the pins of the answer
	 * are cut by, so the client removes exactly what the server has hidden instead of deriving the border
	 * from the recent row it was given.
	 *
	 * @return array<int, int>
	 */
	private function getHiddenHistoryBorders(): array
	{
		$chatIds = $this->chats->getHiddenHistoryChatIds();
		$this->primeStartIds($chatIds);

		$borders = [];

		foreach ($chatIds as $chatId)
		{
			$borders[$chatId] = max(0, $this->getStartId($chatId) - 1);
		}

		return $borders;
	}

	/**
	 * The borders of the whole set a cut is about to walk, in one selection each instead of a select per
	 * chat: a window of the journal names a chat per message, and the border of every one of them is made
	 * of two numbers - the HISTORY_START_ID a cleanup for everyone left on the chat, and the START_ID of
	 * the relation of the requester.
	 *
	 * Two numbers, and nothing but the two: a window this wide names chats by the thousand, and asking each
	 * of them for itself would carry a whole chat row and its whole parameter set into the caches of the
	 * process for the sake of one integer, and keep them there until the answer is sent. The border row is
	 * read straight from the table instead, one range over IX_B_IM_CHAT_PARAM_1 (CHAT_ID, PARAM_NAME) per
	 * chunk - the same reading {@see \Bitrix\Im\V2\Recent\PreviewSource\OwnMessageQuery::readHistoryBorders()}
	 * does, and for the same reason: a border written by another hit has to be seen.
	 *
	 * The border of the chat is worth reading even without a requester: it belongs to the chat, not to the
	 * reader.
	 *
	 * @param int[] $chatIds
	 */
	private function primeStartIds(array $chatIds): void
	{
		$chatIds = array_filter(
			array_unique(array_map('intval', $chatIds)),
			fn (int $chatId) => $chatId > 0 && !isset($this->historyBorders[$chatId])
		);

		if (empty($chatIds))
		{
			return;
		}

		foreach (array_chunk($chatIds, self::BORDER_PREFETCH_CHUNK) as $chunk)
		{
			$this->fillHistoryBorders($chunk);
		}

		$userId = $this->getBorderUserId();

		if ($userId === null)
		{
			return;
		}

		RelationCollection::fillStartIds($chatIds, $userId);
	}

	/**
	 * A chat the selection brings no row for is remembered as 0, the answer a chat without a cleanup gives:
	 * otherwise every one of them would fall back to a read of its own, and chats without a cleanup are the
	 * overwhelming majority of any window.
	 *
	 * @param int[] $chatIds
	 */
	private function fillHistoryBorders(array $chatIds): void
	{
		foreach ($chatIds as $chatId)
		{
			$this->historyBorders[$chatId] = 0;
		}

		$rows = ChatParamTable::query()
			->setSelect(['CHAT_ID', 'PARAM_VALUE'])
			->whereIn('CHAT_ID', $chatIds)
			->where('PARAM_NAME', Params::HISTORY_START_ID)
			->fetchAll()
		;

		foreach ($rows as $row)
		{
			$chatId = (int)$row['CHAT_ID'];
			// The highest of them: a chat cleaned by an earlier version of this path may carry more than one row.
			$this->historyBorders[$chatId] = max($this->historyBorders[$chatId] ?? 0, (int)$row['PARAM_VALUE']);
		}
	}

	/**
	 * The border of the user the answer is built for, composed once per chat and kept here. A chat the
	 * prefetch says nothing about is still read on its own, the way it always was.
	 */
	private function getStartId(int $chatId): int
	{
		if (isset($this->startIds[$chatId]))
		{
			return $this->startIds[$chatId];
		}

		$this->primeStartIds([$chatId]);

		$userId = $this->getBorderUserId();
		$relationStartId = $userId === null ? 0 : RelationCollection::getStartId($userId, $chatId);

		$this->startIds[$chatId] = max($relationStartId, $this->historyBorders[$chatId] ?? 0);

		return $this->startIds[$chatId];
	}

	/**
	 * Without a requester there is no relation to read: SyncService takes the id from the context, so a
	 * context without a user leaves the border to the chat alone - which is the very answer a relation
	 * lookup for user 0 gives.
	 */
	private function getBorderUserId(): ?int
	{
		return $this->currentUserId > 0 ? $this->currentUserId : null;
	}

	protected function fillRestDialogData(): array
	{
		return $this->dialogIds->load($this->rest)->toRestFormat();
	}
}
