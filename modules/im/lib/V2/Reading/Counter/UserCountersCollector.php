<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Reading\Counter;

use Bitrix\Im\Model\ChatTable;
use Bitrix\Im\Model\MessageUnreadTable;
use Bitrix\Im\Model\RecentTable;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Message\Counter\CounterOverflowService;
use Bitrix\Im\V2\Reading\Counter\Entity\UserCounters;
use Bitrix\Im\V2\Reading\Counter\Internal\CountersCache;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Im\V2\Recent\Config\RecentConfigManager;
use Bitrix\Im\V2\Chat\Type\TypeRegistry;

class UserCountersCollector
{
	private const LIMIT = 1000;

	public function __construct(
		protected readonly RecentConfigManager $recentConfigManager,
		protected readonly TypeRegistry $typeRegistry,
		protected readonly CountersCache $countersCache,
		protected readonly CounterOverflowService $counterOverflowService,
	) {}

	public function get(int $userId): UserCounters
	{
		$counters = $this->countersCache->get($userId);
		if ($counters !== null)
		{
			return $counters;
		}

		$counters = $this->fetchAndBuild($userId);
		$this->countersCache->set($userId, $counters);

		return $counters;
	}

	protected function fetchAndBuild(int $userId): UserCounters
	{
		$groupedMessageCounters = $this->fetchGroupedMessageCounters($userId);
		$groupedMessageCounters = $this->enrichWithChatData($groupedMessageCounters, $userId);
		$unreadChats = $this->fetchUnreadChats($userId);
		$parentChats = $this->fetchUnknownParentChats($groupedMessageCounters, $unreadChats);
		$additionalOpenLinesCounters = $this->fetchAdditionalOpenLinesCounters($userId);

		return $this->buildUserCounters(
			$groupedMessageCounters,
			$unreadChats,
			$additionalOpenLinesCounters,
			$parentChats,
		);
	}

	protected function buildUserCounters(
		array $groupedMessageCounters,
		array $unreadChats,
		array $additionalOpenLinesCounters,
		array $parentChats,
	): UserCounters
	{
		$counters = new UserCounters();
		foreach ($groupedMessageCounters as $counter)
		{
			$type = $this->getType($counter);
			if ($type->literal === Chat::IM_TYPE_SYSTEM)
			{
				$counters->setNotificationCounter((int)($counter['COUNT'] ?? 0));
			}
			else
			{
				$counters->addMessageCounter($counter, $this->getRecentSections($type), $type);
			}
		}
		foreach ($unreadChats as $chat)
		{
			$type = $this->getType($chat);
			$counters->addUnreadChat($chat, $this->getRecentSections($type), $type);
		}
		foreach ($additionalOpenLinesCounters as $chatId)
		{
			$type = $this->getType(['CHAT_TYPE' => Chat::IM_TYPE_OPEN_LINE]);
			$counters->addAdditionalOpenLineCounter($chatId, $this->getRecentSections($type), $type);
		}
		foreach ($parentChats as $chat)
		{
			$type = $this->getType($chat);
			$counters->addParentChat($chat, $this->getRecentSections($type), $type);
		}

		return $counters;
	}

	protected function fetchGroupedMessageCounters(int $userId): array
	{
		$overflowedChatIds = $this->counterOverflowService->getOverflowedChatIdsByUserId(
			$userId,
			$this->getLimit()
		);
		$limit = $this->getLimit() - count($overflowedChatIds);
		$query = MessageUnreadTable::query()
			->setSelect([
				'COUNT' => new ExpressionField('COUNT', 'COUNT(*)'),
				'CHAT_ID',
				'MAX_MESSAGE_ID' => new ExpressionField('MAX_MESSAGE_ID', 'MAX(%s)', ['MESSAGE_ID']),
			])
			->setGroup(['CHAT_ID'])
			->setOrder(['MAX_MESSAGE_ID' => 'DESC'])
			->where('USER_ID', $userId)
			->setLimit($limit)
		;
		if (!empty($overflowedChatIds))
		{
			$query->whereNotIn('CHAT_ID', $overflowedChatIds);
		}
		$rows = $query->fetchAll();
		foreach ($overflowedChatIds as $chatId)
		{
			$rows[] = ['CHAT_ID' => $chatId, 'COUNT' => $this->counterOverflowService::getOverflowValue()];
		}

		return $rows;
	}

	protected function fetchUnreadChats(int $userId): array
	{
		return RecentTable::query()
			->setSelect([
				'CHAT_ID' => 'ITEM_CID',
				'CHAT_TYPE' => 'ITEM_TYPE',
				'CHAT_ENTITY_TYPE' => 'CHAT.ENTITY_TYPE',
				'IS_MUTED' => 'RELATION.NOTIFY_BLOCK',
			])
			->where('USER_ID', $userId)
			->where('UNREAD', true)
			->fetchAll()
		;
	}

	protected function fetchAdditionalOpenLinesCounters(int $userId): array
	{
		if (Loader::includeModule('imopenlines'))
		{
			return \Bitrix\ImOpenLines\Recent::getNonAnsweredLines($userId);
		}

		return [];
	}

	protected function enrichWithChatData(array $counters, int $userId): array
	{
		$countersWithChatData = [];
		$chatIds = array_map('intval', array_column($counters, 'CHAT_ID'));
		$chatData = $this->fetchChatData($chatIds, $userId);

		foreach ($counters as $counter)
		{
			if (!array_key_exists($counter['CHAT_ID'], $chatData))
			{
				continue;
			}

			$enrichedCounter = $chatData[$counter['CHAT_ID']];
			$enrichedCounter['COUNT'] = (int)$counter['COUNT'];
			$countersWithChatData[] = $enrichedCounter;
		}

		return $countersWithChatData;
	}

	protected function fetchChatData(array $chatIds, int $userId): array
	{
		if (empty($chatIds))
		{
			return [];
		}

		$result = [];
		$raw = ChatTable::query()
			->setSelect([
				'CHAT_ID' => 'ID',
				'CHAT_ENTITY_TYPE' => 'ENTITY_TYPE',
				'CHAT_TYPE' => 'TYPE',
				'PARENT_ID' => 'PARENT_ID',
				'IS_MUTED' => 'RELATION.NOTIFY_BLOCK',
			])
			->whereIn('ID', $chatIds)
			->withRelation($userId)
			->fetchAll()
		;
		foreach ($raw as $chat)
		{
			$result[$chat['CHAT_ID']] = $chat;
		}

		return $result;
	}

	protected function fetchUnknownParentChats(array $groupedMessageCounters, array $unreadChats): array
	{
		$ids = [];
		$parentIds = [];
		$unknownParentIds = [];
		foreach ($groupedMessageCounters as $counters)
		{
			$id = (int)$counters['CHAT_ID'];
			$parentId = (int)$counters['PARENT_ID'];
			$ids[$id] = $id;
			if ($parentId)
			{
				$parentIds[$parentId] = $parentId;
			}
		}

		foreach ($unreadChats as $unreadChat)
		{
			$id = (int)$unreadChat['CHAT_ID'];
			$ids[$id] = $id;
		}

		foreach ($parentIds as $parentId)
		{
			if (!array_key_exists($parentId, $ids))
			{
				$unknownParentIds[$parentId] = $parentId;
			}
		}

		return $this->fetchParentChats($unknownParentIds);
	}

	protected function fetchParentChats(array $unknownParentIds): array
	{
		if (empty($unknownParentIds))
		{
			return [];
		}

		return ChatTable::query()
			->setSelect([
				'CHAT_ID' => 'ID',
				'CHAT_TYPE' => 'TYPE',
				'CHAT_ENTITY_TYPE' => 'ENTITY_TYPE',
			])
			->whereIn('ID', $unknownParentIds)
			->fetchAll()
		;
	}

	protected function getType(array $counter): Chat\Type
	{
		return $this->typeRegistry->getByLiteralAndEntity(
			$counter['CHAT_TYPE'] ?? '',
			$counter['CHAT_ENTITY_TYPE'] ?? null
		);
	}

	protected function getRecentSections(Chat\Type $type): array
	{
		return $this->recentConfigManager->getRecentSectionsByChatExtendedType($type->getExtendedType(false));
	}

	private function getLimit(): int
	{
		return (int)Option::get('im', 'counter_chats_limit', self::LIMIT);
	}
}
