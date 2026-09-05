<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Sync;

use Bitrix\Im\Model\LogTable;
use Bitrix\Im\V2\Common\ContextCustomer;
use Bitrix\Im\V2\Sync\Entity\EntityFactory;
use Bitrix\Main\Config\Option;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Type\DateTime;

class SyncService
{
	use ContextCustomer;

	private const MODULE_ID = 'im';
	private const ENABLE_OPTION_NAME = 'sync_logger_enable';

	public static function isEnable(): bool
	{
		return Option::get(self::MODULE_ID, self::ENABLE_OPTION_NAME, 'Y') === 'Y';
	}

	public function getChangesFromDate(DateTime $lastDate, ?int $lastId, int $limit): array
	{
		if (!self::isEnable())
		{
			return [];
		}

		$currentUserId = $this->getContext()->getUserId();

		$rowsUser = $this->fetchRowsByUserId($currentUserId, $lastDate, $lastId, $limit);
		$rowsGlobal = $this->fetchRowsByUserId(0, $lastDate, $lastId, $limit);

		$mergedCount = 0;
		$merged = $this->mergeAndSlice($rowsUser, $rowsGlobal, $limit, $mergedCount);
		$hasMore = $mergedCount > $limit || count($rowsUser) >= $limit || count($rowsGlobal) >= $limit;

		return $this->formatData($merged, $lastDate, $lastId, $hasMore, $currentUserId);
	}

	/**
	 * Fetches log rows for one USER_ID with keyset pagination on (DATE_CREATE, ID).
	 * Strict ID > $lastId (not >=) avoids re-fetching the cursor row / infinite loop
	 * when rows share DATE_CREATE. $lastId === null = legacy first page (DATE_CREATE >=).
	 * Separate query per USER_ID to use the (USER_ID, DATE_CREATE) index.
	 */
	private function fetchRowsByUserId(int $userId, DateTime $lastDate, ?int $lastId, int $limit): array
	{
		$query = LogTable::query()
			->setSelect(['ID', 'USER_ID', 'ENTITY_TYPE', 'ENTITY_ID', 'EVENT', 'DATE_CREATE'])
			->where('USER_ID', $userId)
			->setLimit($limit)
			->setOrder(['DATE_CREATE' => 'ASC', 'ID' => 'ASC'])
		;

		if (isset($lastId))
		{
			$query->where(Query::filter()
				->logic('or')
				->where('DATE_CREATE', '>', $lastDate)
				->where(Query::filter()
					->where('DATE_CREATE', '=', $lastDate)
					->where('ID', '>', $lastId)
				)
			);
		}
		else
		{
			$query->where('DATE_CREATE', '>=', $lastDate);
		}

		return $query->fetchAll();
	}

	/**
	 * ALG-01: merge both sources, sort by (DATE_CREATE, ID), cut to $limit.
	 * Cursor must come from the LAST row of the slice (not the max of all fetched),
	 * else rows between the slice boundary and the max are skipped next page.
	 * $mergedCount = total before slicing (for exact hasMore).
	 *
	 * Both $rowsUser and $rowsGlobal arrive pre-sorted by (DATE_CREATE ASC, ID ASC)
	 * from fetchRowsByUserId, so a linear 2-way merge suffices — O(n) time and
	 * zero extra allocations vs the prior array_merge + usort approach O(n log n).
	 * The resulting order is identical to the old usort comparator.
	 */
	private function mergeAndSlice(array $rowsUser, array $rowsGlobal, int $limit, int &$mergedCount): array
	{
		$merged = [];
		$userIndex = 0;
		$globalIndex = 0;
		$userCount = count($rowsUser);
		$globalCount = count($rowsGlobal);

		while ($userIndex < $userCount && $globalIndex < $globalCount)
		{
			$userRow = $rowsUser[$userIndex];
			$globalRow = $rowsGlobal[$globalIndex];

			$userTimestamp = $userRow['DATE_CREATE'] instanceof DateTime ? $userRow['DATE_CREATE']->getTimestamp() : 0;
			$globalTimestamp = $globalRow['DATE_CREATE'] instanceof DateTime ? $globalRow['DATE_CREATE']->getTimestamp() : 0;

			if ($userTimestamp < $globalTimestamp || ($userTimestamp === $globalTimestamp && (int)$userRow['ID'] <= (int)$globalRow['ID']))
			{
				$merged[] = $userRow;
				$userIndex++;
			}
			else
			{
				$merged[] = $globalRow;
				$globalIndex++;
			}
		}

		while ($userIndex < $userCount)
		{
			$merged[] = $rowsUser[$userIndex++];
		}

		while ($globalIndex < $globalCount)
		{
			$merged[] = $rowsGlobal[$globalIndex++];
		}

		$mergedCount = count($merged);

		return array_slice($merged, 0, $limit);
	}

	private function formatData(array $logEvents, DateTime $incomingLastDate, ?int $incomingLastId, bool $hasMore, int $currentUserId = 0): array
	{
		$entities = (new EntityFactory())->createEntities($logEvents, $currentUserId);
		$rest = $entities->getRestData();
		$rest['navigationData'] = $this->getNavigationData($logEvents, $incomingLastDate, $incomingLastId, $hasMore);

		return $rest;
	}

	protected function getNavigationData(array $logEvents, DateTime $incomingLastDate, ?int $incomingLastId, bool $hasMore): array
	{
		if (empty($logEvents))
		{
			// empty page: preserve incoming cursor (ALG-01)
			return [
				'lastServerDate' => $incomingLastDate,
				'hasMore' => $hasMore,
				'lastId' => $incomingLastId ?? 0,
			];
		}

		// Cursor = last row of the already-sliced page (ALG-01 invariant).
		$lastRow = end($logEvents);
		$lastDate = $lastRow['DATE_CREATE'] instanceof DateTime ? $lastRow['DATE_CREATE'] : null;
		$lastId = (int)$lastRow['ID'];

		return [
			'lastServerDate' => $lastDate,
			'hasMore' => $hasMore,
			'lastId' => $lastId,
		];
	}
}
