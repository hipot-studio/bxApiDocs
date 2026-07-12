<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Public\Dto\Counter;

use Bitrix\Im\V2\Common\Collection\CountableTrait;
use Bitrix\Im\V2\Common\Collection\IteratorAggregateTrait;
use Bitrix\Im\V2\Common\Normalizer;
use Bitrix\Im\V2\Reading\Counter\Entity\UserCounters;

final class ChatCounterCollection implements \IteratorAggregate, \Countable
{
	use IteratorAggregateTrait;
	use CountableTrait;

	/** @var array<int, ChatCounterItem> */
	private array $items;
	/** @var array<int, int> */
	private array $parentByChatId;

	/**
	 * @param array<int, ChatCounterItem> $items
	 * @param array<int, int> $parentByChatId
	 */
	private function __construct(array $items = [], array $parentByChatId = [])
	{
		$this->items = $items;
		$this->parentByChatId = $parentByChatId;
	}

	public static function empty(): self
	{
		return new self();
	}

	/**
	 * @internal
	 *
	 * @param array<int, ChatCounterItem> $items
	 * @param array<int, int> $parentByChatId
	 */
	public static function fromItems(array $items, array $parentByChatId = []): self
	{
		return new self($items, $parentByChatId);
	}

	/**
	 * @internal
	 *
	 * @param int[] $rootChatIds
	 */
	public static function fromUserCounters(UserCounters $counters, array $rootChatIds): self
	{
		if ($counters->isEmpty())
		{
			return self::empty();
		}

		$childrenByParentId = self::indexChildrenByParentId($counters);
		$parentByChatId = self::indexParentByChatId($counters);

		$items = [];
		foreach ($rootChatIds as $rootChatId)
		{
			$item = self::buildRootItem(
				(int)$rootChatId,
				$counters,
				$childrenByParentId,
				$parentByChatId,
			);
			if ($item !== null)
			{
				$items[$item->chatId] = $item;
			}
		}

		return new self($items, $parentByChatId);
	}

	protected function &getArray(): array
	{
		return $this->items;
	}

	public function getByChatId(int $chatId): ?ChatCounterItem
	{
		if ($chatId <= 0)
		{
			return null;
		}

		return $this->items[$chatId] ?? null;
	}

	public function getTotalCount(?ChatCounterAggregationOptions $options = null): int
	{
		return $this->getTotalCountForChatIds(array_keys($this->items), $options);
	}

	public function getTotalCountForChat(int $chatId, ?ChatCounterAggregationOptions $options = null): ?int
	{
		$item = $this->getByChatId($chatId);
		if ($item === null)
		{
			return null;
		}

		return $this->sumForRequestedItem($item, $options ?? new ChatCounterAggregationOptions());
	}

	/**
	 * @param int[] $chatIds
	 */
	public function getTotalCountForChatIds(array $chatIds, ?ChatCounterAggregationOptions $options = null): int
	{
		$options ??= new ChatCounterAggregationOptions();

		$total = 0;
		foreach ($this->normalizeRequestedChatIdsForAggregation($chatIds) as $chatId)
		{
			$total += $this->sumForRequestedItem($this->items[$chatId], $options);
		}

		return $total;
	}

	private function sumForRequestedItem(ChatCounterItem $item, ChatCounterAggregationOptions $options): int
	{
		$sum = $item->getSum($options);
		if (!$options->includeMutedRoots && $item->isMuted)
		{
			$sum -= $item->ownCounter;
		}

		return $sum;
	}

	/** @return array<int, ChatCounterItem> */
	public function getRaw(): array
	{
		return $this->items;
	}

	/**
	 * @internal
	 */
	public function merge(self $collection): self
	{
		return new self(
			$this->items + $collection->items,
			$this->parentByChatId + $collection->parentByChatId,
		);
	}

	/** @return array<int, int[]> */
	private static function indexChildrenByParentId(UserCounters $counters): array
	{
		$childrenByParentId = [];

		foreach ($counters as $counter)
		{
			if ($counter->parentChatId > 0)
			{
				$childrenByParentId[$counter->parentChatId][] = $counter->chatId;
			}
		}

		return $childrenByParentId;
	}

	private static function buildRootItem(
		int $chatId,
		UserCounters $counters,
		array $childrenByParentId,
		array $parentByChatId,
	): ?ChatCounterItem
	{
		$counter = $counters->getByChatId($chatId);
		if ($counter === null)
		{
			return null;
		}

		$path = [$chatId => true];
		$children = self::buildChildren($chatId, $counters, $childrenByParentId, $parentByChatId, $path);

		return ChatCounterItem::fromInternalCounter($counter, $children);
	}

	private static function buildCounterItem(
		int $chatId,
		UserCounters $counters,
		array $childrenByParentId,
		array $parentByChatId,
		array $path,
	): ?ChatCounterItem
	{
		if (isset($path[$chatId]))
		{
			return null;
		}

		$counter = $counters->getByChatId($chatId);
		if ($counter === null)
		{
			return null;
		}

		$path[$chatId] = true;

		return ChatCounterItem::fromInternalCounter(
			$counter,
			self::buildChildren($chatId, $counters, $childrenByParentId, $parentByChatId, $path),
		);
	}

	private static function buildChildren(
		int $chatId,
		UserCounters $counters,
		array $childrenByParentId,
		array $parentByChatId,
		array $path,
	): self
	{
		$children = [];
		foreach ($childrenByParentId[$chatId] ?? [] as $childChatId)
		{
			$child = self::buildCounterItem($childChatId, $counters, $childrenByParentId, $parentByChatId, $path);
			if ($child !== null)
			{
				$children[$child->chatId] = $child;
			}
		}

		return new self($children, $parentByChatId);
	}

	/** @return array<int, int> */
	private static function indexParentByChatId(UserCounters $counters): array
	{
		$parentByChatId = [];

		foreach ($counters as $counter)
		{
			$parentByChatId[$counter->chatId] = $counter->parentChatId;
		}

		return $parentByChatId;
	}

	/**
	 * @param int[] $chatIds
	 * @return int[]
	 */
	private function normalizeRequestedChatIdsForAggregation(array $chatIds): array
	{
		$requestedChatIds = [];
		foreach (Normalizer::toUniquePositiveIntegers($chatIds) as $chatId)
		{
			if (isset($this->items[$chatId]))
			{
				$requestedChatIds[$chatId] = $chatId;
			}
		}

		$result = [];
		foreach ($requestedChatIds as $chatId)
		{
			if (!$this->hasRequestedAncestor($chatId, $requestedChatIds))
			{
				$result[] = $chatId;
			}
		}

		return $result;
	}

	/**
	 * @param array<int, int> $requestedChatIds
	 */
	private function hasRequestedAncestor(int $chatId, array $requestedChatIds): bool
	{
		$visited = [];
		$parentChatId = $this->parentByChatId[$chatId] ?? 0;

		while ($parentChatId > 0)
		{
			if (isset($visited[$parentChatId]))
			{
				return false;
			}

			if (isset($requestedChatIds[$parentChatId]))
			{
				return true;
			}

			$visited[$parentChatId] = true;
			$parentChatId = $this->parentByChatId[$parentChatId] ?? 0;
		}

		return false;
	}
}
