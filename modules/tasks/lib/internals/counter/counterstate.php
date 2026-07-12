<?php
/**
 * Bitrix Framework
 * @package bitrix
 * @subpackage tasks
 * @copyright 2001-2021 Bitrix
 */

namespace Bitrix\Tasks\Internals\Counter;

use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\Internals\Counter;
use Bitrix\Tasks\V2\Internal\Integration\Socialnetwork\Service\FeatureService;
use Bitrix\Tasks\V2\Internal\Integration\Socialnetwork\UserService;

abstract class CounterState implements \Iterator
{
	private Counter\Loader $loader;

	protected int $userId;
	protected array $counters = [];
	protected array $mentions = [];
	private FeatureService $sonetFeatureService;

	/**
	 * CounterState constructor.
	 * @param int $userId
	 */
	protected function __construct(int $userId, Counter\Loader $loader)
	{
		$this->userId = $userId;
		$this->loader = $loader;
		$this->sonetFeatureService = Container::getInstance()->get(FeatureService::class);
		$this->init();
	}

	abstract public function rewind(): void;

	#[\ReturnTypeWillChange]
	abstract public function current();

	#[\ReturnTypeWillChange]
	abstract public function key();

	abstract public function next(): void;

	abstract public function valid(): bool;

	abstract public function getSize(): int;

	abstract protected function loadCounters(): void;

	abstract public function updateState(array $rawCounters, array $types = [], array $taskIds = []): void;

	public function init(): void
	{
		$this->counters = $this->getCountersEmptyState();
		$this->loadCounters();
	}

	public function getLoader(): Counter\Loader
	{
		return $this->loader;
	}

	public function isCounted(): bool
	{
		return $this->loader->isCounted();
	}

	public function getClearedDate(): int
	{
		return $this->loader->getClearedDate();
	}

	public function resetCache(): void
	{
		$this->loader->resetCache();
	}

	/**
	 * @param string $meta
	 * @return array
	 */
	public function getRawCounters(string $meta = CounterDictionary::META_PROP_ALL): array
	{
		return $this->counters[$meta] ?? [];
	}

	/**
	 * @param string $name
	 * @param int|null $groupId
	 * @return int
	 */
	public function getValue(string $name, int $groupId = null): int
	{
		$counters = $this->counters[CounterDictionary::META_PROP_ALL];

		if ($groupId > 0)
		{
			if (
				!array_key_exists($name, $counters)
				|| !array_key_exists($groupId, $counters[$name])
			)
			{
				return 0;
			}

			return $counters[$name][$groupId];
		}

		if (!array_key_exists($name, $counters))
		{
			return 0;
		}

		return array_sum($counters[$name]);
	}

	/**
	 * Updates counters based on current state
	 * @return void
	 */
	protected function updateRawCounters(): void
	{
		$this->mentions = [];
		$this->counters = $this->getCountersEmptyState();

		$userService = Container::getInstance()->get(UserService::class);
		$groups = $userService->getGroups($this->userId);
		$projects = $userService->getProjects($this->userId);
		$scrum = $userService->getScrum($this->userId);
		$collabs = $userService->getCollabs($this->userId);

		$sonetGroupMap = [
			CounterDictionary::META_PROP_PROJECT => true,
			CounterDictionary::META_PROP_GROUP => true,
		];

		if ($this->sonetFeatureService->isNewProjectsOn())
		{
			$sonetGroupMap[CounterDictionary::META_PROP_COLLAB] = true;
		}

		$tmpHeap[] = [];
		foreach ($this as $item)
		{
			if ($this->getLoader()->isCounterFlag($item['TYPE']))
			{
				continue;
			}

			$taskId = $item['TASK_ID'];
			$groupId = $item['GROUP_ID'];
			$value = $item['VALUE'];
			$type = $item['TYPE'];
			$flowId = $item['FLOW_ID'] ?? 0;

			$meta = $this->getMetaProp($item, $groups, $projects, $scrum, $collabs);
			$subType = $this->getItemSubType($type);

			$this->counterInitIfNotExist($meta, $type, $groupId);
			$this->counterInitIfNotExist($meta, $subType, $groupId);

			if (!isset($tmpHeap[$meta][$subType][$groupId]))
			{
				$tmpHeap[$meta][$subType][$groupId] = [];
			}

			$isSonet = \array_key_exists($meta, $sonetGroupMap);

			$this->counterInitIfNotExist(CounterDictionary::META_PROP_SONET, $type, $groupId);
			$this->counterInitIfNotExist(CounterDictionary::META_PROP_SONET, $subType, $groupId);
			$this->counterInitIfNotExist(CounterDictionary::META_PROP_ALL, $type, $groupId);
			$this->counterInitIfNotExist(CounterDictionary::META_PROP_ALL, $subType, $groupId);

			// flow
			if (
				$flowId
				&& in_array($type, CounterDictionary::FLOW_TYPES)
				&& !isset($tmpHeap[CounterDictionary::META_PROP_FLOW][$type][$flowId][$taskId]))
			{
				$tmpHeap[CounterDictionary::META_PROP_FLOW][$type][$flowId][$taskId] = $value;
				$this->counterAddValue($value, CounterDictionary::META_PROP_FLOW, $type, $flowId);
				$this->counterAddValue($value, CounterDictionary::META_PROP_FLOW, $type, 0);
			}

			if (!isset($tmpHeap[$meta][$type][$groupId][$taskId]))
			{
				$tmpHeap[$meta][$type][$groupId][$taskId] = $value;
				$this->counters[$meta][$type][$groupId] += $value;

				if ($isSonet)
				{
					$this->counters[CounterDictionary::META_PROP_SONET][$type][$groupId] += $value;
				}

				if ($type === CounterDictionary::COUNTER_MENTIONED)
				{
					$this->addMention($meta, $groupId, $taskId, $flowId, $value, $isSonet);
				}
			}

			if (
				$type !== $subType
				&& !isset($tmpHeap[$meta][$subType][$groupId][$taskId])
			)
			{
				$tmpHeap[$meta][$subType][$groupId][$taskId] = $value;
				$this->counters[$meta][$subType][$groupId] += $value;

				if ($isSonet)
				{
					$this->counters[CounterDictionary::META_PROP_SONET][$subType][$groupId] += $value;
				}
			}

			if (!isset($tmpHeap[CounterDictionary::META_PROP_ALL][$type][$groupId][$taskId]))
			{
				$tmpHeap[CounterDictionary::META_PROP_ALL][$type][$groupId][$taskId] = $value;
				$this->counters[CounterDictionary::META_PROP_ALL][$type][$groupId] += $value;
			}

			if (
				$type !== $subType
				&& !isset($tmpHeap[CounterDictionary::META_PROP_ALL][$subType][$groupId][$taskId])
			)
			{
				$tmpHeap[CounterDictionary::META_PROP_ALL][$subType][$groupId][$taskId] = $value;
				$this->counters[CounterDictionary::META_PROP_ALL][$subType][$groupId] += $value;
			}

			if ($meta === CounterDictionary::META_PROP_NONE)
			{
				continue;
			}

			// Total sum for the all groups/projects
			$this->counterInitIfNotExist(CounterDictionary::META_PROP_ALL, CounterDictionary::COUNTER_GROUPS_TOTAL_EXPIRED, 0);
			$this->counterInitIfNotExist(CounterDictionary::META_PROP_ALL, CounterDictionary::COUNTER_GROUPS_TOTAL_COMMENTS, 0);
			$this->counterInitIfNotExist(CounterDictionary::META_PROP_ALL, CounterDictionary::COUNTER_GROUPS_FOREIGN_EXPIRED, 0);
			$this->counterInitIfNotExist(CounterDictionary::META_PROP_ALL, CounterDictionary::COUNTER_GROUPS_FOREIGN_COMMENTS, 0);

			$this->counterInitIfNotExist(CounterDictionary::META_PROP_SONET, CounterDictionary::COUNTER_GROUPS_TOTAL_EXPIRED, 0);
			$this->counterInitIfNotExist(CounterDictionary::META_PROP_SONET, CounterDictionary::COUNTER_GROUPS_TOTAL_COMMENTS, 0);
			$this->counterInitIfNotExist(CounterDictionary::META_PROP_SONET, CounterDictionary::COUNTER_GROUPS_FOREIGN_EXPIRED, 0);
			$this->counterInitIfNotExist(CounterDictionary::META_PROP_SONET, CounterDictionary::COUNTER_GROUPS_FOREIGN_COMMENTS, 0);

			$this->counterInitIfNotExist($meta, CounterDictionary::COUNTER_GROUPS_TOTAL_EXPIRED, 0);
			$this->counterInitIfNotExist($meta, CounterDictionary::COUNTER_GROUPS_FOREIGN_COMMENTS, 0);
			$this->counterInitIfNotExist($meta, CounterDictionary::COUNTER_GROUPS_FOREIGN_EXPIRED, 0);

			if (
				$subType === CounterDictionary::COUNTER_EXPIRED
				&& !isset($tmpHeap[$meta][CounterDictionary::COUNTER_GROUPS_TOTAL_EXPIRED][0][$taskId])
			)
			{
				$this->counters[$meta][CounterDictionary::COUNTER_GROUPS_TOTAL_EXPIRED][0] += $value;
				$this->counters[$meta][CounterDictionary::COUNTER_GROUPS_FOREIGN_EXPIRED][0] -= $value;
				if ($isSonet)
				{
					$this->counters[CounterDictionary::META_PROP_ALL][CounterDictionary::COUNTER_GROUPS_TOTAL_EXPIRED][0] += $value;
					$this->counters[CounterDictionary::META_PROP_ALL][CounterDictionary::COUNTER_GROUPS_FOREIGN_EXPIRED][0] -= $value;
					$this->counters[CounterDictionary::META_PROP_SONET][CounterDictionary::COUNTER_GROUPS_TOTAL_EXPIRED][0] += $value;
					$this->counters[CounterDictionary::META_PROP_SONET][CounterDictionary::COUNTER_GROUPS_FOREIGN_EXPIRED][0] -= $value;
				}

				$tmpHeap[$meta][CounterDictionary::COUNTER_GROUPS_TOTAL_EXPIRED][0][$taskId] = $value;
			}

			if (
				$subType === CounterDictionary::COUNTER_NEW_COMMENTS
				&& !isset($tmpHeap[$meta][CounterDictionary::COUNTER_GROUPS_TOTAL_COMMENTS][0][$taskId])
			)
			{
				$this->counterInitIfNotExist($meta, CounterDictionary::COUNTER_GROUPS_TOTAL_COMMENTS, 0);

				$this->counters[$meta][CounterDictionary::COUNTER_GROUPS_TOTAL_COMMENTS][0] += $value;
				$this->counters[$meta][CounterDictionary::COUNTER_GROUPS_FOREIGN_COMMENTS][0] -= $value;
				if ($isSonet)
				{
					$this->counters[CounterDictionary::META_PROP_ALL][CounterDictionary::COUNTER_GROUPS_TOTAL_COMMENTS][0] += $value;
					$this->counters[CounterDictionary::META_PROP_ALL][CounterDictionary::COUNTER_GROUPS_FOREIGN_COMMENTS][0] -= $value;
					$this->counters[CounterDictionary::META_PROP_SONET][CounterDictionary::COUNTER_GROUPS_TOTAL_COMMENTS][0] += $value;
					$this->counters[CounterDictionary::META_PROP_SONET][CounterDictionary::COUNTER_GROUPS_FOREIGN_COMMENTS][0] -= $value;
				}

				$tmpHeap[$meta][CounterDictionary::COUNTER_GROUPS_TOTAL_COMMENTS][0][$taskId] = $value;
			}

			if (
				in_array($subType, [CounterDictionary::COUNTER_GROUP_EXPIRED, CounterDictionary::COUNTER_MUTED_EXPIRED])
				&& !isset($tmpHeap[$meta][CounterDictionary::COUNTER_GROUPS_FOREIGN_EXPIRED][0][$taskId])
			)
			{
				$this->counters[$meta][CounterDictionary::COUNTER_GROUPS_FOREIGN_EXPIRED][0] += $value;
				if ($isSonet)
				{
					$this->counters[CounterDictionary::META_PROP_ALL][CounterDictionary::COUNTER_GROUPS_FOREIGN_EXPIRED][0] += $value;
					$this->counters[CounterDictionary::META_PROP_SONET][CounterDictionary::COUNTER_GROUPS_FOREIGN_EXPIRED][0] += $value;
				}

				$tmpHeap[$meta][CounterDictionary::COUNTER_GROUPS_FOREIGN_EXPIRED][0][$taskId] = $value;
			}

			if (
				in_array($subType, [CounterDictionary::COUNTER_GROUP_COMMENTS, CounterDictionary::COUNTER_MUTED_NEW_COMMENTS])
				&& !isset($tmpHeap[$meta][CounterDictionary::COUNTER_GROUPS_FOREIGN_COMMENTS][0][$taskId])
			)
			{
				$this->counters[$meta][CounterDictionary::COUNTER_GROUPS_FOREIGN_COMMENTS][0] += $value;
				if ($isSonet)
				{
					$this->counters[CounterDictionary::META_PROP_ALL][CounterDictionary::COUNTER_GROUPS_FOREIGN_COMMENTS][0] += $value;
					$this->counters[CounterDictionary::META_PROP_SONET][CounterDictionary::COUNTER_GROUPS_FOREIGN_COMMENTS][0] += $value;
				}

				$tmpHeap[$meta][CounterDictionary::COUNTER_GROUPS_FOREIGN_COMMENTS][0][$taskId] = $value;
			}
		}

		$this->applyMentions($tmpHeap);

		// clear extra cache

		$this->mentions = [];
		unset($tmpHeap);
	}

	private function getCountersEmptyState(): array
	{
		return [
			CounterDictionary::META_PROP_ALL => [],
			CounterDictionary::META_PROP_PROJECT => [],
			CounterDictionary::META_PROP_GROUP => [],
			CounterDictionary::META_PROP_COLLAB => [],
			CounterDictionary::META_PROP_SONET => [],
			CounterDictionary::META_PROP_SCRUM => [],
			CounterDictionary::META_PROP_FLOW => [],
			CounterDictionary::META_PROP_NONE => [],
		];
	}

	/**
	 * @param array $item
	 * @return string
	 */
	private function getItemSubType(string $type): string
	{
		if (in_array($type, CounterDictionary::MAP_EXPIRED))
		{
			return CounterDictionary::COUNTER_EXPIRED;
		}

		if (in_array($type, CounterDictionary::MAP_MUTED_EXPIRED))
		{
			return CounterDictionary::COUNTER_MUTED_EXPIRED;
		}

		if (in_array($type, CounterDictionary::MAP_COMMENTS))
		{
			return CounterDictionary::COUNTER_NEW_COMMENTS;
		}

		if (in_array($type, CounterDictionary::MAP_MUTED_COMMENTS))
		{
			return CounterDictionary::COUNTER_MUTED_NEW_COMMENTS;
		}

		return $type;
	}

	/**
	 * @param array $item
	 * @param array $groups
	 * @param array $projects
	 * @param array $scrum
	 * @return string
	 */
	private function getMetaProp(array $item, array $groups, array $projects, array $scrum, array $collabs): string
	{
		if (array_key_exists($item['GROUP_ID'], $scrum))
		{
			return CounterDictionary::META_PROP_SCRUM;
		}

		if (array_key_exists($item['GROUP_ID'], $groups))
		{
			return CounterDictionary::META_PROP_GROUP;
		}

		if (array_key_exists($item['GROUP_ID'], $projects))
		{
			return CounterDictionary::META_PROP_PROJECT;
		}

		if (array_key_exists($item['GROUP_ID'], $collabs))
		{
			return CounterDictionary::META_PROP_COLLAB;
		}

		return CounterDictionary::META_PROP_NONE;
	}

	private function counterInitIfNotExist(string $meta, string $counter, int $groupId): void
	{
		if (!isset($this->counters[$meta][$counter][$groupId]))
		{
			$this->counters[$meta][$counter][$groupId] = 0;
		}
	}

	private function addMention(string $meta, int $groupId, int $taskId, int $flowId, int $value, bool $isSonet): void
	{
		$key = sprintf('%s|%s|%s|%s', $meta, $groupId, $taskId, $flowId);
		$this->mentions[$key] = [
			'meta' => $meta,
			'groupId' => $groupId,
			'flowId' => $flowId,
			'value' => $value,
			'taskId' => $taskId,
			'isSonet' => $isSonet,
		];
	}

	/**
	 * convert muted message counter into the new message counter for tasks that contains mention and muted messages
	 */
	private function applyMentions(array $tmpHeap): void
	{
		foreach ($this->mentions as $mentionInfo)
		{
			$meta = $mentionInfo['meta'];
			$groupId = $mentionInfo['groupId'];
			$value = $mentionInfo['value'];
			$taskId = $mentionInfo['taskId'];
			$flowId = $mentionInfo['flowId'];
			$isSonet = $mentionInfo['isSonet'];

			if (!isset($tmpHeap[$meta][CounterDictionary::COUNTER_MUTED_NEW_COMMENTS][$groupId][$taskId]))
			{
				continue;
			}

			$this->counterAddValue(-$value, $meta, CounterDictionary::COUNTER_MUTED_NEW_COMMENTS, $groupId);
			$this->counterAddValue($value, $meta, CounterDictionary::COUNTER_MUTED_MENTIONED, $groupId);

			if ($meta === CounterDictionary::META_PROP_SCRUM)
			{
				// scrum total use scrum-meta
				$this->counterAddValue($value, $meta, CounterDictionary::COUNTER_GROUPS_TOTAL_COMMENTS, $groupId);
			}
			else
			{
				$this->counterAddValue($value, $meta, CounterDictionary::COUNTER_NEW_COMMENTS, $groupId);
			}

			if ($flowId > 0)
			{
				// flow total use flow-meta
				$this->counterAddValue($value, CounterDictionary::META_PROP_FLOW, CounterDictionary::COUNTER_ORIGINATOR_NEW_COMMENTS, $flowId);
				$this->counterAddValue($value, CounterDictionary::META_PROP_FLOW, CounterDictionary::COUNTER_ORIGINATOR_NEW_COMMENTS, 0);
			}

			if ($groupId > 0)
			{
				$this->counterAddValue($value, $meta, CounterDictionary::COUNTER_GROUPS_TOTAL_COMMENTS, 0);
			}

			if (
				$meta !== CounterDictionary::META_PROP_ALL
				&& isset($tmpHeap[CounterDictionary::META_PROP_ALL][CounterDictionary::COUNTER_MUTED_NEW_COMMENTS][$groupId][$taskId])
			)
			{
				// add no-all-meta counters to all total
				$this->counterAddValue(-$value, CounterDictionary::META_PROP_ALL, CounterDictionary::COUNTER_MUTED_NEW_COMMENTS, $groupId);
				$this->counterAddValue($value, CounterDictionary::META_PROP_ALL, CounterDictionary::COUNTER_NEW_COMMENTS, $groupId);
				$this->counterAddValue($value, CounterDictionary::META_PROP_ALL, CounterDictionary::COUNTER_MUTED_MENTIONED, $groupId);

				if ($groupId > 0 && $isSonet)
				{
					// groups and projects use group total from meta-all
					$this->counterAddValue($value, CounterDictionary::META_PROP_ALL, CounterDictionary::COUNTER_GROUPS_TOTAL_COMMENTS, 0);
					$this->counterAddValue($value, CounterDictionary::META_PROP_ALL, CounterDictionary::COUNTER_SONET_MUTED_MENTIONED, 0);
				}
			}
		}
	}

	private function counterAddValue(int $value, string $meta, string $counter, int $groupId): void
	{
		$this->counterInitIfNotExist($meta, $counter, $groupId);
		$this->counters[$meta][$counter][$groupId] += $value;
	}
}
