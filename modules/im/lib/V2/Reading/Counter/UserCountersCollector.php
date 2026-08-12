<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Reading\Counter;

use Bitrix\Im\Model\ChatTable;
use Bitrix\Im\Model\MessageUnreadTable;
use Bitrix\Im\Model\RecentTable;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\Tree\ChatTreeResolver;
use Bitrix\Im\V2\Chat\Tree\ResolvedChatNode;
use Bitrix\Im\V2\Message\Counter\CounterOverflowService;
use Bitrix\Im\V2\Reading\Counter\Entity\UserCounters;
use Bitrix\Im\V2\Reading\Counter\Internal\CountersCache;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Query\Join;
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
		protected readonly ChatTreeResolver $chatTreeResolver,
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
		$parentChats = $this->fetchUnknownParentChats($groupedMessageCounters, $unreadChats, $userId);
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
				$counters->addMessageCounter($counter, $this->getRecentSections($type, (int)($counter['PARENT_ID'] ?? 0)), $type);
			}
		}
		foreach ($unreadChats as $chat)
		{
			$type = $this->getType($chat);
			$counters->addUnreadChat($chat, $this->getRecentSections($type, (int)($chat['PARENT_ID'] ?? 0)), $type);
		}
		foreach ($additionalOpenLinesCounters as $chatId)
		{
			$type = $this->getType(['CHAT_TYPE' => Chat::IM_TYPE_OPEN_LINE]);
			$counters->addAdditionalOpenLineCounter($chatId, $this->getRecentSections($type), $type);
		}
		foreach ($parentChats as $chat)
		{
			$type = $this->getType($chat);
			// A parent the user belongs to but which is absent from their recent list is flagged
			// hidden centrally by ChatTreeResolver (RecentTable LEFT join). Empty its recentSections
			// so child counters don't "bubble up" into the default/collab badge through an invisible
			// parent. The parent stays in the registry, so the child is not orphaned.
			$sections = ($chat['IS_HIDDEN'] ?? false)
				? []
				: $this->getRecentSections($type, (int)($chat['PARENT_ID'] ?? 0));

			$counters->addParentChat($chat, $sections, $type);
		}

		$counters->removeOrphaned();

		return $counters;
	}

	protected function fetchGroupedMessageCounters(int $userId): array
	{
		$overflowedChatIds = $this->counterOverflowService->getOverflowedChatIdsByUserId(
			$userId,
			$this->getLimit(),
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
				'PARENT_ID' => 'CHAT.PARENT_ID',
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
		$result = [];
		foreach ($this->fetchChatRows($chatIds, $userId) as $chat)
		{
			$result[$chat['CHAT_ID']] = $chat;
		}

		return $result;
	}

	protected function fetchUnknownParentChats(array $groupedMessageCounters, array $unreadChats, int $userId): array
	{
		$knownIds = [];
		foreach ($groupedMessageCounters as $counters)
		{
			$knownIds[(int)$counters['CHAT_ID']] = true;
		}
		foreach ($unreadChats as $unreadChat)
		{
			$knownIds[(int)$unreadChat['CHAT_ID']] = true;
		}

		$unknownParentIds
			= $this->collectUnknownParentIds($groupedMessageCounters, $knownIds)
			+ $this->collectUnknownParentIds($unreadChats, $knownIds);

		return array_map(
			static fn(ResolvedChatNode $node): array => [
				'CHAT_ID' => $node->chatId,
				'CHAT_TYPE' => $node->chatType,
				'CHAT_ENTITY_TYPE' => $node->entityType,
				'PARENT_ID' => $node->parentChatId,
				'IS_MUTED' => $node->isMuted ? 'Y' : 'N',
				'IS_HIDDEN' => $node->isHidden,
			],
			$this->chatTreeResolver->resolveForUser($userId, array_values($unknownParentIds), array_keys($knownIds)),
		);
	}

	private function collectUnknownParentIds(array $chats, array $knownIds): array
	{
		$unknownParentIds = [];
		foreach ($chats as $chat)
		{
			$parentId = (int)($chat['PARENT_ID'] ?? 0);
			if ($parentId > 0 && !isset($knownIds[$parentId]))
			{
				$unknownParentIds[$parentId] = $parentId;
			}
		}

		return $unknownParentIds;
	}

	protected function fetchChatRows(array $chatIds, int $userId): array
	{
		if (empty($chatIds))
		{
			return [];
		}

		$query = ChatTable::query()
			->setSelect([
				'CHAT_ID' => 'ID',
				'CHAT_TYPE' => 'TYPE',
				'CHAT_ENTITY_TYPE' => 'ENTITY_TYPE',
				'PARENT_ID' => 'PARENT_ID',
				'IS_MUTED' => 'RELATION.NOTIFY_BLOCK',
			])
			->whereIn('ID', $chatIds)
			->withRelation($userId, Join::TYPE_INNER)
		;

		return $query->fetchAll();
	}

	protected function getType(array $counter): Chat\Type
	{
		return $this->typeRegistry->getByLiteralAndEntity(
			$counter['CHAT_TYPE'] ?? '',
			$counter['CHAT_ENTITY_TYPE'] ?? null,
		);
	}

	protected function getRecentSections(Chat\Type $type, int $parentId = 0): array
	{
		if ($parentId > 0)
		{
			return $this->recentConfigManager->getAllRecentSectionsByType($type->getExtendedType(false));
		}

		return $this->recentConfigManager->getBaseRecentSections($type->getExtendedType(false));
	}

	private function getLimit(): int
	{
		return (int)Option::get('im', 'counter_chats_limit', self::LIMIT);
	}
}
