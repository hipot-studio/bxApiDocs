<?php

namespace Bitrix\Im\V2\Sync;

use Bitrix\Im\Model\ChatTable;
use Bitrix\Im\Recent;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\Copilot\CopilotPopupItem;
use Bitrix\Im\V2\Chat\MessagesAutoDelete\MessagesAutoDeleteConfigs;
use Bitrix\Im\V2\Common\ContextCustomer;
use Bitrix\Im\V2\Entity\User\UserPopupItem;
use Bitrix\Im\V2\Reading\Counter\UserCountersCollector;
use Bitrix\Im\V2\Sync\Recent\RecentSync;
use Bitrix\Im\V2\Rest\PopupData;
use Bitrix\Im\V2\Rest\PopupDataAggregatable;
use Bitrix\Main\DI\ServiceLocator;

class ChatsSync extends Chat\ChatPopupItem implements PopupDataAggregatable
{
	use ContextCustomer;

	protected RecentSync $recent;
	protected UserCountersCollector $countersCollector;
	protected bool $withCounters = false;

	/** @var array<int, true> Chats answered with their counter even when the answer as a whole carries none. */
	protected array $counterChatIds = [];

	/**
	 * @param int[] $counterChatIds
	 */
	public function __construct(array $chats, RecentSync $recent, bool $withCounters = false, array $counterChatIds = [])
	{
		$this->recent = $recent;
		$this->countersCollector = ServiceLocator::getInstance()->get(UserCountersCollector::class);
		$this->withCounters = $withCounters;
		$this->counterChatIds = array_fill_keys(array_map('intval', $counterChatIds), true);

		parent::__construct($chats);
	}

	public function toRestFormat(array $option = []): array
	{
		$rest = [];

		$this->fillAllForRest();
		// Phase 0 (task 718250): superadmin project chat access
		Chat::fillManageCapability($this->chats);
		$this->fillDialogIds();

		foreach ($this->chats as $chat)
		{
			if ($chat instanceof Chat\NullChat)
			{
				continue;
			}

			$restData = $chat->toRestFormat(['CHAT_SHORT_FORMAT' => true]);
			$rest[] = array_merge($restData, $this->getSelfAdditionalParams($chat));
		}

		return $rest;
	}

	protected function getSelfAdditionalParams(Chat $chat): array
	{
		$data = [
			'dateCreate' => $chat->getDateCreate()?->format('c'),
			'lastMessageId' => $chat->getLastMessageId(),
			'lastId' => $chat->getLastId(),
			'managerList' => $chat->getManagerList(false),
			'markedId' => $chat->getMarkedId(),
			'messageCount' => $chat->getMessageCount(),
			'public' => $chat->getPublicOption() ?? '',
			'unreadId' => $chat->getUnreadId(),
			'userCounter' => $chat->getUserCount(),
		];

		if ($this->withCounters || isset($this->counterChatIds[$chat->getId()]))
		{
			$userId = $this->getContext()->getUserId();
			// a chat the collector knows nothing about has nothing unread
			$data['counter'] = $this->countersCollector->get($userId)->getByChatId($chat->getId())?->counter ?? 0;
		}

		return $data;
	}

	protected function fillAllForRest(): void
	{
		$chatIds = $this->getChatIds();

		$nonCachedData = $this->fillNonCachedData($chatIds);
		$markedIds = $this->fillMarkedIds($chatIds);

		$this->fillData($nonCachedData, $markedIds);
	}

	protected function fillData(array $nonCachedData, array $markedIds): void
	{
		foreach ($nonCachedData as $chatData)
		{
			$chatId = (int)$chatData['ID'];
			$chat = $this->chats[$chatId] ?? null;
			if ($chat === null)
			{
				continue;
			}

			$chat
				->setMessageCount((int)$chatData['MESSAGE_COUNT'])
				->setUserCount((int)$chatData['USER_COUNT'])
				->setLastMessageId((int)$chatData['LAST_MESSAGE_ID'])
				->setMarkedId($markedIds[$chatId] ?? 0)
				->markFilledNonCachedData(true)
			;
		}
	}

	protected function fillNonCachedData(array $chatIds): array
	{
		if (empty($chatIds))
		{
			return [];
		}

		return ChatTable::query()
			->setSelect(array_merge(Chat::NON_CACHED_FIELDS, ['ID']))
			->whereIn('ID', $chatIds)
			->fetchAll()
		;
	}

	protected function fillMarkedIds(array $chatIds): array
	{
		if (!empty($this->recent))
		{
			return $this->fillMarkedIdsByRecent();
		}

		return Recent::getMarkedIdByChatIds($this->getContext()->getUserId(), $chatIds);
	}

	protected function fillMarkedIdsByRecent(): array
	{
		$markedIds = [];
		foreach ($this->recent as $recentItem)
		{
			$markedIds[$recentItem->getChatId()] = $recentItem->getMarkedId();
		}

		return $markedIds;
	}

	protected function getUsers(): array
	{
		$userIds =
			!empty($this->chats)
				? [$this->getContext()->getUserId()]
				: []
		;

		foreach ($this->chats as $chat)
		{
			if ($chat instanceof Chat\PrivateChat)
			{
				$userIds[] = (int)$chat->getDialogId();
			}
		}

		return $userIds;
	}

	public function getPopupData(array $excludedList = []): PopupData
	{
		$this->fillDialogIds();

		return new PopupData([
			CopilotPopupItem::getInstanceByChatIds($this->getChatIds()),
			(new MessagesAutoDeleteConfigs($this->getChatIds()))->showDefaultValues(),
			new UserPopupItem($this->getUsers()),
			$this->recent,
		], $excludedList);
	}
}
