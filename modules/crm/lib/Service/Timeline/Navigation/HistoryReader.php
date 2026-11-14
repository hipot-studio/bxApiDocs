<?php

namespace Bitrix\Crm\Service\Timeline\Navigation;

use Bitrix\Crm\Service\Timeline\Item;
use Bitrix\Crm\Service\Timeline\Repository;
use Bitrix\Crm\Service\Timeline\Repository\Query;
use Bitrix\Main\Type\DateTime;

final class HistoryReader
{
	private const BATCH_SIZE = 25;
	private const MAX_BATCHES = 8;

	public function __construct(
		private readonly Repository $repository,
		private readonly ?PredicateCompiler $compiler = null,
	)
	{
	}

	public function read(NavigationSnapshot $snapshot, ?CursorPayload $cursor, int $limit, array $filter): array
	{
		if ($limit < 1 || $limit > 50)
		{
			throw new \InvalidArgumentException('Invalid history limit');
		}
		$compiler = $this->compiler ?? new PredicateCompiler();
		$query = (new Query())->setFilter($filter)->setLimit(self::BATCH_SIZE);
		if ($cursor !== null)
		{
			$position = $cursor->get('position', []);
			if ((int)($position['id'] ?? 0) <= 0 || !is_string($position['created'] ?? null))
			{
				throw new \InvalidArgumentException('Invalid history position');
			}
			$query
				->setOffsetId((int)$position['id'])
				->setOffsetTime(new DateTime($position['created'], 'Y-m-d H:i:s'))
			;
		}
		$items = [];
		$batches = 0;
		while (true)
		{
			$batches++;
			$page = $this->repository->getNavigationCandidatesPage($query);
			if (!$page->isSuccess())
			{
				throw new \RuntimeException('Unable to read timeline history');
			}
			$positions = $page->getData()['rawPositions'] ?? [];
			$projected = [];
			foreach ($page->getItems() as $item)
			{
				$projected[(int)$item->getModel()->getId()] = $item;
			}
			$remaining = count($positions);
			foreach ($positions as $id => $position)
			{
				$date = $position['created'] ?? null;
				if ($id <= 0 || !$date instanceof DateTime)
				{
					throw new \RuntimeException('Invalid timeline history position');
				}
				$query->setOffsetId($id)->setOffsetTime($date);
				$remaining--;
				if (
					isset($projected[$id])
					&& $compiler->matchesProjectedItem($projected[$id], $snapshot->crm, $position)
				)
				{
					$items[] = $projected[$id];
				}
				if (count($items) === $limit)
				{
					$hasMore = $remaining > 0;
					if (!$hasMore && count($positions) === self::BATCH_SIZE)
					{
						$lookAhead = $this->repository->getNavigationCandidatesPage((clone $query)->setLimit(1));
						if (!$lookAhead->isSuccess())
						{
							throw new \RuntimeException('Unable to read timeline history');
						}
						$hasMore = ($lookAhead->getData()['rawPositions'] ?? []) !== [];
					}

					return [
						'items' => $items, 'hasMore' => $hasMore,
						'rawPosition' => $hasMore ? ['id' => $id, 'created' => $date->format('Y-m-d H:i:s')] : null,
					];
				}
			}
			if (count($positions) < self::BATCH_SIZE)
			{
				return ['items' => $items, 'rawPosition' => null, 'hasMore' => false];
			}
			if ($batches >= self::MAX_BATCHES)
			{
				return [
					'items' => $items,
					'rawPosition' => ['id' => $id, 'created' => $date->format('Y-m-d H:i:s')],
					'hasMore' => true,
				];
			}
		}
	}

	public function readFixed(NavigationSnapshot $snapshot, array $filter): array
	{
		$compiler = $this->compiler ?? new PredicateCompiler();
		$page = $this->repository->getNavigationCandidatesPage(
			(new Query())
				->setSearchForFixedItems(true)
				->setFilter($filter)
				->setLimit(20),
		);
		if (!$page->isSuccess())
		{
			throw new \RuntimeException('Unable to read fixed timeline history');
		}

		$positions = $page->getData()['rawPositions'] ?? [];

		return array_values(array_filter(
			$page->getItems(),
			static function(Item $item) use ($compiler, $snapshot, $positions): bool
			{
				$id = (int)$item->getModel()->getId();

				return $compiler->matchesProjectedItem($item, $snapshot->crm, $positions[$id] ?? []);
			},
		));
	}
}
