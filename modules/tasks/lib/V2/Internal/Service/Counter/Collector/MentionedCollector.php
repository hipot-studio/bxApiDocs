<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Counter\Collector;

use Bitrix\Im\V2\Anchor\AnchorItem;
use Bitrix\Tasks\Internals\Counter\CounterDictionary;
use Bitrix\Tasks\Internals\Counter\Event\Event;
use Bitrix\Tasks\Internals\Counter\Event\EventCollection;
use Bitrix\Tasks\Internals\Counter\Event\EventDictionary;
use Bitrix\Tasks\V2\Internal\Entity\CounterCollection;
use Bitrix\Tasks\V2\Internal\Entity\Counter;
use Bitrix\Tasks\V2\Internal\Integration\Im\Service\ImAnchorProviderDelegate;
use Bitrix\Tasks\V2\Internal\Logger;
use Bitrix\Tasks\V2\Internal\Repository\ChatRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\CounterRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\GroupRepositoryInterface;

/**
 * Collector for mention counters.
 */
class MentionedCollector
{
	public function __construct(
		private readonly ImAnchorProviderDelegate $anchorProvider,
		private readonly CounterRepositoryInterface $repository,
		private readonly ChatRepositoryInterface $chatRepository,
		private readonly GroupRepositoryInterface $groupRepository,
		private readonly Logger $logger,
	) {
	}

	/**
	 * Main process method for collecting mention counters.
	 * Orchestrates the entire flow while delegating specific tasks to private methods.
	 */
	public function process(): void
	{
		$events = EventCollection::getInstance()->list();
		$mentionEvents = $this->filterMentionEvents($events);

		if (empty($mentionEvents))
		{
			return;
		}

		$mentionCounters = $this->calculateMentionCounters($mentionEvents);
		$counterCollection = $this->createCounterCollection($mentionEvents, $mentionCounters);

		if ($counterCollection->isEmpty())
		{
			return;
		}

		foreach ($counterCollection as $counter)
		{
			$this->repository->deleteByUserAndTaskAndType($counter->userId, $counter->taskId, $counter->type);
		}

		$this->repository->createFromCollection($counterCollection);
	}

	/**
	 * Recounts mention counters for specific user and tasks.
	 * This method is useful when you need to update counters for specific user-task combinations.
	 * 
	 * @param int $userId User ID to recount mentions for
	 * @param array $taskIds Array of task IDs to recount mentions for
	 */
	public function recount(int $userId, array $taskIds): void
	{
		if ($userId <= 0 || empty($taskIds))
		{
			return;
		}

		// Filter out invalid task IDs
		$validTaskIds = array_filter($taskIds, fn(int $taskId): bool => $taskId > 0);
		if (empty($validTaskIds))
		{
			return;
		}

		$this->repository->deleteByUserAndTaskAndType($userId, $validTaskIds, CounterDictionary::COUNTER_MENTIONED);

		// Calculate new mention counters
		$mentionCounters = $this->calculateMentionCountersForUser($userId, $validTaskIds);
		
		if (empty($mentionCounters))
		{
			return;
		}

		// Create counter collection
		$counterCollection = $this->createCounterCollectionForUser($userId, $validTaskIds, $mentionCounters);
		
		if (!$counterCollection->isEmpty())
		{
			$this->repository->createFromCollection($counterCollection);
		}
	}

	/**
	 * Filters events to get only mention events.
	 *
	 * @param Event[] $events
	 * @return Event[]
	 */
	private function filterMentionEvents(array $events): array
	{
		return array_filter(
			$events,
			fn (Event $event): bool => $event->getType() === EventDictionary::EVENT_AFTER_USER_MENTIONED,
		);
	}

	/**
	 * Calculates mention counters for the given events.
	 *
	 * @param Event[] $events
	 * @return array<int, array<int, int>> Array mapping [userId => [chatId => count]]
	 */
	private function calculateMentionCounters(array $events): array
	{
		[$userIds, $taskIds] = $this->extractUniqueIds($events);
		$anchorData = $this->fetchAnchorData($userIds);
		$chatToTaskMapping = $this->getChatToTaskMapping($taskIds);

		return $this->buildMentionCounters($anchorData, $chatToTaskMapping);
	}

	/**
	 * Extracts unique user IDs and task IDs from events.
	 *
	 * @param Event[] $events
	 * @return array{0: int[], 1: int[]} Tuple of unique user IDs and task IDs.
	 */
	private function extractUniqueIds(array $events): array
	{
		$userIds = [];
		$taskIds = [];

		foreach ($events as $event)
		{
			$userId = $event->getUserId();
			$taskId = $event->getTaskId();

			if ($userId > 0)
			{
				$userIds[$userId] = $userId;
			}

			if ($taskId > 0)
			{
				$taskIds[$taskId] = $taskId;
			}
		}

		return [array_values($userIds), array_values($taskIds)];
	}

	/**
	 * Fetches anchor data for given user IDs.
	 *
	 * @param int[] $userIds
	 * @return array<int, array>
	 */
	private function fetchAnchorData(array $userIds): array
	{
		$anchorData = [];

		foreach ($userIds as $userId)
		{
			try
			{
				$anchorData[$userId] = $this->anchorProvider->getUserAnchors($userId);
			}
			catch (\Throwable $e)
			{
				$anchorData[$userId] = [];
				$this->logger->logError($e);
			}
		}

		return $anchorData;
	}

	/**
	 * Gets chat to task mapping for the given task IDs.
	 *
	 * @param int[] $taskIds
	 * @return array<int, int> Array mapping [chatId => taskId]
	 */
	private function getChatToTaskMapping(array $taskIds): array
	{
		if (empty($taskIds))
		{
			return [];
		}

		// findChatIdsByTaskIds returns [taskId => chatId]
		$taskToChatMapping = $this->chatRepository->findChatIdsByTaskIds($taskIds);

		// Flip to get [chatId => taskId]
		return array_flip($taskToChatMapping);
	}

	/**
	 * Builds mention counters from anchor data.
	 *
	 * @param array<int, array{chatId: int, type: string}> $anchorData
	 * @param array<int, int> $chatToTaskMapping Array mapping [chatId => taskId]
	 * @return array<int, array<int, int>> Array mapping [userId => [taskId => count]]
	 */
	private function buildMentionCounters(array $anchorData, array $chatToTaskMapping): array
	{
		$mentionCounters = [];

		foreach ($anchorData as $userId => $anchors)
		{
			$mentionCounters[$userId] = [];

			if (!is_array($anchors))
			{
				continue;
			}

			foreach ($anchors as $anchor)
			{
				if (!$this->isValidMentionAnchor($anchor))
				{
					continue;
				}

				$chatId = (int)$anchor['chatId'];

				// Check if this chat is related to any of our tasks
				if (!isset($chatToTaskMapping[$chatId]))
				{
					continue;
				}

				$taskId = $chatToTaskMapping[$chatId];

				if (!isset($mentionCounters[$userId][$taskId]))
				{
					$mentionCounters[$userId][$taskId] = 0;
				}

				$mentionCounters[$userId][$taskId]++;
			}
		}

		return $mentionCounters;
	}

	/**
	 * Validates if anchor is a valid mention anchor.
	 */
	private function isValidMentionAnchor(mixed $anchor): bool
	{
		return is_array($anchor)
			&& isset($anchor['type'], $anchor['chatId'])
			&& $anchor['type'] === AnchorItem::MENTION
			&& (int)$anchor['chatId'] > 0;
	}

	/**
	 * Creates a counter's collection from events and mention counters.
	 *
	 * @param Event[] $events
	 * @param array<int, array<int, int>> $mentionCounters
	 */
	private function createCounterCollection(array $events, array $mentionCounters): CounterCollection
	{
		$collection = new CounterCollection();

		foreach ($events as $event)
		{
			$counterEntity = $this->createCounterEntity($event, $mentionCounters);
			if ($counterEntity !== null)
			{
				$collection->add($counterEntity);
			}
		}

		return $collection;
	}

	/**
	 * Creates a single counter entity from event and mention counters.
	 */
	private function createCounterEntity(Event $event, array $mentionCounters): ?Counter
	{
		$userId = $event->getUserId();
		$taskId = $event->getTaskId();

		if ($userId <= 0 || $taskId <= 0)
		{
			return null; // Skip invalid events
		}

		// Note: There seems to be some confusion in original code between taskId and chatId
		// Based on the original logic, using taskId as the key for mention counters
		$counterValue = $mentionCounters[$userId][$taskId] ?? 0;

		return new Counter(
			taskId: $taskId,
			groupId: $event->getGroupId(),
			userId: $userId,
			type: CounterDictionary::COUNTER_MENTIONED,
			value: $counterValue,
		);
	}

	/**
	 * Calculates mention counters for a specific user and task IDs.
	 * This is a specialized version of calculateMentionCounters for recount operations.
	 *
	 * @param int $userId User ID to calculate counters for
	 * @param int[] $taskIds Array of task IDs to calculate counters for
	 * @return array<int, array<int, int>> Array mapping [userId => [taskId => count]]
	 */
	private function calculateMentionCountersForUser(int $userId, array $taskIds): array
	{
		if ($userId <= 0 || empty($taskIds))
		{
			return [];
		}

		$anchorData = $this->fetchAnchorData([$userId]);
		$chatToTaskMapping = $this->getChatToTaskMapping($taskIds);

		return $this->buildMentionCounters($anchorData, $chatToTaskMapping);
	}

	/**
	 * Creates a counter collection for a specific user and tasks.
	 * This is a specialized version for recount operations.
	 *
	 * @param int $userId User ID
	 * @param int[] $taskIds Array of task IDs
	 * @param array<int, array<int, int>> $mentionCounters Mention counters data
	 */
	private function createCounterCollectionForUser(int $userId, array $taskIds, array $mentionCounters): CounterCollection
	{
		$groupIds = $this->groupRepository->getGroupIdsByTaskIds($taskIds);

		$collection = new CounterCollection();

		foreach ($taskIds as $taskId)
		{
			$counterValue = $mentionCounters[$userId][$taskId] ?? 0;
			
			if ($counterValue > 0)
			{
				$counter = new Counter(
					taskId: $taskId,
					groupId: (int)($groupIds[$taskId] ?? 0),
					userId: $userId,
					type: CounterDictionary::COUNTER_MENTIONED,
					value: $counterValue,
				);
				
				$collection->add($counter);
			}
		}

		return $collection;
	}
}
