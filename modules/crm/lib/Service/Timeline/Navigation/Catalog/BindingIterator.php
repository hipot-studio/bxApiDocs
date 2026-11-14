<?php

namespace Bitrix\Crm\Service\Timeline\Navigation\Catalog;

use Bitrix\Crm\Timeline\Entity\TimelineBindingTable;
use Bitrix\Main\ORM\Query\Query;

final class BindingIterator
{
	public const BATCH_SIZE = 25;

	public function next(array $owner, ?array $cursor): array
	{
		// a cursor of the former partitioned walk restarts the pass from the beginning
		$cursor = $this->isCurrentCursor($cursor) ? $cursor : null;
		$highWater = $cursor['highWater'] ?? $this->loadHighWater($owner);
		$lastOwnerId = $cursor['lastOwnerId'] ?? 0;
		$ids = $lastOwnerId < $highWater ? $this->loadIds($owner, $lastOwnerId, $highWater) : [];
		$done = count($ids) < self::BATCH_SIZE || end($ids) >= $highWater;

		return [
			'ids' => $ids,
			'done' => $done,
			'cursor' => $done ? null : [
				'lastOwnerId' => end($ids),
				'highWater' => $highWater,
			],
		];
	}

	private function isCurrentCursor(?array $cursor): bool
	{
		return
			$cursor !== null
			&& is_int($cursor['lastOwnerId'] ?? null)
			&& is_int($cursor['highWater'] ?? null)
		;
	}

	private function loadHighWater(array $owner): int
	{
		$row = $this->ownerQuery($owner)
			->setOrder(['OWNER_ID' => 'DESC'])
			->setLimit(1)
			->fetch()
		;

		return (int)($row['OWNER_ID'] ?? 0);
	}

	private function loadIds(array $owner, int $lastOwnerId, int $highWater): array
	{
		$rows = $this->ownerQuery($owner)
			->where('OWNER_ID', '>', $lastOwnerId)
			->where('OWNER_ID', '<=', $highWater)
			->setOrder(['OWNER_ID' => 'ASC'])
			->setLimit(self::BATCH_SIZE)
			->fetchAll()
		;

		return array_map(
			static fn(array $row): int => (int)$row['OWNER_ID'],
			$rows,
		);
	}

	private function ownerQuery(array $owner): Query
	{
		return TimelineBindingTable::query()
			->setSelect(['OWNER_ID'])
			->where('ENTITY_TYPE_ID', (int)$owner['entityTypeId'])
			->where('ENTITY_ID', (int)$owner['entityId'])
		;
	}
}
