<?php

namespace Bitrix\Im\V2\Chat\Comment;

use Bitrix\Im\Model\ChatTable;
use Bitrix\Im\V2\Chat\ChatFactory;
use Bitrix\Im\V2\Common\ContextCustomer;
use Bitrix\Im\V2\Entity\User\UserShortPopupItem;
use Bitrix\Im\V2\Message\LastMessages;
use Bitrix\Im\V2\Rest\PopupData;
use Bitrix\Im\V2\Rest\PopupDataAggregatable;
use Bitrix\Im\V2\Rest\PopupDataItem;
use Bitrix\Im\V2\Rest\PopupDataOptionAware;

class CommentPopupItem implements PopupDataItem, PopupDataAggregatable, PopupDataOptionAware
{
	use ContextCustomer;

	/**
	 * @var array<int, array<int, int>> parent chat id => ids of its root posts
	 */
	private array $parentMap;
	private array $userIds = [];
	private array $childChatIds = [];
	private ?array $commentsInfo = null;
	private ?LastMessages $lastMessages = null;
	private bool $withSubscriptionFlag = true;
	private bool $withThreadCapabilityGate = false;

	public function __construct(int $chatId = 0, array $messageIds = [])
	{
		$this->parentMap = self::buildParentMap([$chatId => $messageIds]);
	}

	/**
	 * Creates the item for root posts of several parent chats at once.
	 *
	 * @param array<int, int[]> $parentMap parent chat id => ids of its root posts
	 * @return self
	 */
	public static function getInstanceByParentMap(array $parentMap): self
	{
		$instance = new self();
		$instance->parentMap = self::buildParentMap($parentMap);

		return $instance;
	}

	/**
	 * Enables the thread-capability gate for this item. It is off by default: the gate belongs to
	 * the common collection assembly, while the REST list method returns cells regardless of it.
	 *
	 * @return self
	 */
	public function enableThreadCapabilityGate(): self
	{
		$this->withThreadCapabilityGate = true;

		return $this;
	}

	public function merge(PopupDataItem $item): self
	{
		if ($item instanceof self)
		{
			foreach ($item->parentMap as $parentChatId => $messageIds)
			{
				$this->parentMap[$parentChatId] = ($this->parentMap[$parentChatId] ?? []) + $messageIds;
			}

			// A merge can arrive after the first fill: keeping the collected result would drop posts
			// of the joined parent, and keeping userIds would leave author profiles of the past pass.
			$this->commentsInfo = null;
			$this->childChatIds = [];
			$this->userIds = [];
			$this->lastMessages = null;
		}

		return $this;
	}

	public static function getRestEntityName(): string
	{
		return 'commentInfo';
	}

	public function applyOptions(array $option): void
	{
		if ($option['MESSAGE_ONLY_COMMON_FIELDS'] ?? false)
		{
			$this->withSubscriptionFlag = false;
		}
	}

	public function toRestFormat(array $option = []): array
	{
		// The item is also built outside PopupData (the REST list method), and there the options
		// arrive only here. By this point PopupData has already applied them, and the call is idempotent.
		$this->applyOptions($option);

		return $this->getInfo();
	}

	protected function getInfo(): array
	{
		if (empty($this->parentMap))
		{
			return [];
		}

		$this->fill();

		return $this->commentsInfo;
	}

	protected function fill(): void
	{
		$this->fillBaseCommentsInfo();
		$this->fillUsers();
	}

	protected function fillBaseCommentsInfo(): void
	{
		if (isset($this->commentsInfo))
		{
			return;
		}

		$this->commentsInfo = [];

		if (empty($this->parentMap))
		{
			return;
		}

		$query = ChatTable::query()
			->setSelect(['ID', 'MESSAGE_COUNT', 'PARENT_ID', 'PARENT_MID'])
			->whereIn('PARENT_ID', array_keys($this->parentMap))
			->whereIn('PARENT_MID', $this->getAllPostIds())
		;

		if ($this->withSubscriptionFlag)
		{
			$query
				->addSelect('RELATION.NOTIFY_BLOCK', 'NOTIFY_BLOCK')
				->withRelation($this->getContext()->getUserId())
			;
		}

		$rows = [];
		$parentChatIds = [];

		foreach ($query->fetchAll() as $row)
		{
			$parentChatId = (int)$row['PARENT_ID'];
			$messageId = (int)$row['PARENT_MID'];

			// Both lists stay in the filter to keep the IX_IM_CHAT_5 index usable, so the query also
			// returns pairs made of one parent from the map and a post of another one.
			if (!isset($this->parentMap[$parentChatId][$messageId]))
			{
				continue;
			}

			$rows[] = $row;
			$parentChatIds[$parentChatId] = $parentChatId;
		}

		$threadCapableParents = $this->withThreadCapabilityGate
			? self::resolveThreadCapableParents($parentChatIds)
			: []
		;

		foreach ($rows as $row)
		{
			$parentChatId = (int)$row['PARENT_ID'];
			$messageId = (int)$row['PARENT_MID'];

			if ($this->withThreadCapabilityGate && !($threadCapableParents[$parentChatId] ?? false))
			{
				continue;
			}

			$this->childChatIds[] = (int)$row['ID'];
			$cell = [
				'chatId' => (int)$row['ID'],
				'dialogId' => 'chat' . $row['ID'],
				'messageId' => $messageId,
				'lastUserIds' => [],
				'messageCount' => (int)$row['MESSAGE_COUNT'],
			];

			// TPL-01: in the light set of fields the key is absent, not false. Clients treat a
			// present value as authoritative and fall back to their own state only without the key.
			if ($this->withSubscriptionFlag)
			{
				$cell['isUserSubscribed'] = ($row['NOTIFY_BLOCK'] ?? 'Y') === 'N';
			}

			$this->commentsInfo[] = $cell;
		}
	}

	protected function fillUsers(): void
	{
		$this->fillBaseCommentsInfo();
		$lastMessages = $this->getLastMessages();

		foreach ($this->commentsInfo as $key => $commentInfo)
		{
			$lastUserIds = $lastMessages->getUsersByChatId($commentInfo['chatId']);
			$this->commentsInfo[$key]['lastUserIds'] = $lastUserIds;

			foreach ($lastUserIds as $userId)
			{
				$this->userIds[$userId] = $userId;
			}
		}
	}

	protected function getLastMessages(): LastMessages
	{
		if (!isset($this->lastMessages))
		{
			$this->fillBaseCommentsInfo();
			$this->lastMessages = new LastMessages($this->childChatIds);
		}

		return $this->lastMessages;
	}

	public function getPopupData(array $excludedList = []): PopupData
	{
		$this->fillUsers();

		return new PopupData([new UserShortPopupItem($this->userIds)], $excludedList);
	}

	/**
	 * The gate asks about all parents of the page at once: chat by chat it would cost a query per
	 * parent missing from the cache, and a delta page carries messages of many chats.
	 *
	 * @param array<int, int> $parentChatIds
	 * @return array<int, bool> parent chat id => is thread capable
	 */
	private static function resolveThreadCapableParents(array $parentChatIds): array
	{
		$capableParents = [];
		$idsToLoad = [];
		$chatFactory = ChatFactory::getInstance();

		foreach ($parentChatIds as $parentChatId)
		{
			$chat = $chatFactory->getChatFromCache($parentChatId);

			if ($chat === null)
			{
				$idsToLoad[] = $parentChatId;

				continue;
			}

			$capableParents[$parentChatId] = $chat->allowsComments();
		}

		if (empty($idsToLoad))
		{
			return $capableParents;
		}

		$rows = ChatTable::query()
			->setSelect(['*', '_ALIAS' => 'ALIAS.ALIAS'])
			->whereIn('ID', $idsToLoad)
			->fetchAll()
		;

		foreach ($rows as $row)
		{
			$row['ALIAS'] = $row['_ALIAS'];
			$capableParents[(int)$row['ID']] = $chatFactory->initChat($row)->allowsComments();
		}

		return $capableParents;
	}

	/**
	 * @param array<int, int[]> $rawMap
	 * @return array<int, array<int, int>>
	 */
	private static function buildParentMap(array $rawMap): array
	{
		$map = [];

		foreach ($rawMap as $parentChatId => $messageIds)
		{
			$parentChatId = (int)$parentChatId;
			if ($parentChatId <= 0)
			{
				continue;
			}

			foreach ($messageIds as $messageId)
			{
				$messageId = (int)$messageId;
				if ($messageId > 0)
				{
					$map[$parentChatId][$messageId] = $messageId;
				}
			}
		}

		return $map;
	}

	/**
	 * @return int[]
	 */
	private function getAllPostIds(): array
	{
		$postIds = [];

		foreach ($this->parentMap as $messageIds)
		{
			$postIds += $messageIds;
		}

		return array_values($postIds);
	}
}