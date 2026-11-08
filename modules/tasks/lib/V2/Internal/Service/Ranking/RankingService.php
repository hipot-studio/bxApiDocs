<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Ranking;

use Bitrix\Main\Type\Collection;
use Bitrix\Main\Type\DateTime;
use Bitrix\Tasks\Internals\Task\Status;
use Bitrix\Tasks\Provider\Query\TaskQuery;
use Bitrix\Tasks\Provider\TaskList;
use Bitrix\Tasks\V2\Internal\Access\Service\TaskRightService;
use Bitrix\Tasks\V2\Internal\Access\Task\ActionDictionary;

/**
 * Fill-to-limit ranking for the relation selectors.
 *
 * The result is grouped: own active tasks of the card project (1), own active
 * tasks elsewhere (2), own completed tasks (3), then - only if the own tasks did
 * not fill the limit - foreign active (4) and foreign completed (5). Score comes
 * from {@see ScoreCalculator} and orders tasks inside every group.
 */
final class RankingService
{
	private const GROUP_RANK_MY_PROJECT = 1;
	private const GROUP_RANK_MY_OTHER = 2;
	private const GROUP_RANK_MY_COMPLETED = 3;
	private const GROUP_RANK_FOREIGN_ACTIVE = 4;
	private const GROUP_RANK_FOREIGN_COMPLETED = 5;

	private const FOREIGN_RESCORE_POOL = 50;

	// Narrow pool under a text search: there the limit is the scan depth of a
	// leading-wildcard LIKE, not a cheap index range.
	private const SEARCH_RESCORE_POOL = 50;

	public function __construct(
		private readonly ScoreCalculator $scoreCalculator,
		private readonly TaskRightService $taskRightService,
	)
	{
	}

	/**
	 * @param int[] $excludeIds already linked tasks plus the card task itself
	 * @param int|null $parentId parent of the card for the subtask selector
	 * @return list<array{ID: int, TITLE: string, STATUS: int}> ordered for display
	 */
	public function rank(
		int $userId,
		?RelationKind $selectorKind,
		int $cardGroupId,
		array $excludeIds,
		?string $searchQuery,
		int $limit,
		bool $editableOnly = false,
		?int $parentId = null,
	): array
	{
		if ($limit <= 0)
		{
			return [];
		}

		// Only the seed for the SQL candidate queries is capped, so every IN / NOT IN
		// list stays bounded; the full map still scores every candidate.
		$scoreMap = $this->scoreCalculator->calculate($userId, $selectorKind, new DateTime());
		$scoredIds = $this->topScoredIds($scoreMap, RankingConfig::getCandidateLimit());

		// Union of the scored own tasks and the recency pool: a high-score task older
		// than the pool must not be lost.
		$scoredOwn = $this->fetchScoredOwnCandidates($userId, $scoredIds, $excludeIds, $searchQuery);
		$recencyOwn = $this->fetchOwnCandidates(
			$userId,
			$excludeIds,
			$searchQuery,
			$this->ownRecencyPoolLimit($searchQuery, count($scoredOwn), $limit),
		);
		$ownCandidates = $this->filterSubtaskCandidates(
			$this->mergeRowsById($scoredOwn, $recencyOwn),
			$selectorKind,
			$parentId,
		);
		$ownCandidates = $this->filterEditableCandidates(
			$userId,
			$ownCandidates,
			$editableOnly,
		);

		$rankedOwnIds = $this->rankOwn($ownCandidates, $scoreMap, $cardGroupId, $limit);
		$result = $this->hydrate($userId, $rankedOwnIds);

		if (count($result) >= $limit)
		{
			return $result;
		}

		// The whole own union is excluded from the foreign fill, not just what was
		// displayed.
		$ownIds = array_map('intval', array_column($ownCandidates, 'ID'));
		$excludeForeign = array_merge($excludeIds, $ownIds);

		$foreignScoredIds = array_values(array_diff($scoredIds, $ownIds, $excludeIds));
		$scoredForeign = $this->fetchScoredForeign($userId, $foreignScoredIds, $searchQuery);

		$remaining = $limit - count($result);
		$foreign = $this->rankForeign(
			$userId,
			$excludeForeign,
			$searchQuery,
			$remaining,
			$scoreMap,
			$scoredForeign,
			$editableOnly,
			$selectorKind,
			$parentId,
		);
		$result = array_merge($result, $foreign);

		return $result;
	}

	/**
	 * Highest-scoring interacted task ids, capped to $limit. The tie-break by lastAt
	 * makes the truncation deterministic.
	 *
	 * @param array<int, array{score: float, lastAt: int}> $scoreMap
	 * @return list<int>
	 */
	private function topScoredIds(array $scoreMap, int $limit): array
	{
		$ids = array_keys($scoreMap);
		if (count($ids) <= $limit)
		{
			return $ids;
		}

		usort(
			$ids,
			static fn (int $a, int $b): int =>
				($scoreMap[$b]['score'] <=> $scoreMap[$a]['score'])
					?: ($scoreMap[$b]['lastAt'] <=> $scoreMap[$a]['lastAt']),
		);

		return array_slice($ids, 0, $limit);
	}

	/**
	 * MEMBER implies read access, so skipAccessCheck is safe for the own groups.
	 *
	 * @return array<int, array> raw own-task rows (ID, REAL_STATUS, GROUP_ID, PARENT_ID, ACTIVITY_DATE)
	 */
	private function fetchOwnCandidates(int $userId, array $excludeIds, ?string $searchQuery, int $poolLimit): array
	{
		$filter = [
			'MEMBER' => $userId,
			'=ZOMBIE' => 'N',
		];
		$this->applyExclude($filter, $excludeIds);
		$this->applySearch($filter, $searchQuery);

		$query = (new TaskQuery($userId))
			->setSelect(['ID', 'REAL_STATUS', 'GROUP_ID', 'PARENT_ID', 'ACTIVITY_DATE'])
			->setWhere($filter)
			->skipAccessCheck()
			->setOrder(['ACTIVITY_DATE' => 'DESC'])
			->setLimit($poolLimit)
		;

		return (new TaskList())->getList($query);
	}

	/**
	 * Own tasks the user interacted with, regardless of ACTIVITY_DATE recency.
	 *
	 * @param int[] $scoredIds
	 * @return array<int, array> raw own-task rows (ID, REAL_STATUS, GROUP_ID, PARENT_ID, ACTIVITY_DATE)
	 */
	private function fetchScoredOwnCandidates(int $userId, array $scoredIds, array $excludeIds, ?string $searchQuery): array
	{
		$scoredIds = array_values(array_unique(array_filter(array_map('intval', $scoredIds))));
		if ($scoredIds === [])
		{
			return [];
		}

		$filter = [
			'MEMBER' => $userId,
			'ID' => $scoredIds,
			'=ZOMBIE' => 'N',
		];
		$this->applyExclude($filter, $excludeIds);
		$this->applySearch($filter, $searchQuery);

		$query = (new TaskQuery($userId))
			->setSelect(['ID', 'REAL_STATUS', 'GROUP_ID', 'PARENT_ID', 'ACTIVITY_DATE'])
			->setWhere($filter)
			->skipAccessCheck()
			->setLimit(count($scoredIds))
		;

		return (new TaskList())->getList($query);
	}

	/**
	 * @return list<int> final own task ids, in display order (up to $limit)
	 */
	private function rankOwn(array $candidates, array $scoreMap, int $cardGroupId, int $limit): array
	{
		if ($candidates === [])
		{
			return [];
		}

		$inWorkStatuses = Status::getInWorkStatuses();

		$rows = [];
		foreach ($candidates as $candidate)
		{
			$taskId = (int)$candidate['ID'];
			$status = (int)($candidate['REAL_STATUS'] ?? 0);
			$groupId = (int)($candidate['GROUP_ID'] ?? 0);

			$activityTs = ($candidate['ACTIVITY_DATE'] instanceof DateTime)
				? $candidate['ACTIVITY_DATE']->getTimestamp()
				: 0;

			$rows[] = [
				'ID' => $taskId,
				'GROUP_RANK' => $this->classify($status, $groupId, $cardGroupId, $inWorkStatuses),
				'SCORE' => $scoreMap[$taskId]['score'] ?? 0.0,
				'LAST_AT' => $scoreMap[$taskId]['lastAt'] ?? 0,
				'ACTIVITY_TS' => $activityTs,
			];
		}

		// ACTIVITY_TS is the final tie-break: sortByColumn is not stable, so tasks
		// without a score still need a deterministic order.
		Collection::sortByColumn(
			$rows,
			[
				'GROUP_RANK' => SORT_ASC,
				'SCORE' => SORT_DESC,
				'LAST_AT' => SORT_DESC,
				'ACTIVITY_TS' => SORT_DESC,
			],
		);

		$rows = array_slice($rows, 0, $limit);

		return array_map(static fn (array $row): int => $row['ID'], $rows);
	}

	/**
	 * @param int[] $inWorkStatuses
	 */
	private function classify(int $status, int $groupId, int $cardGroupId, array $inWorkStatuses): int
	{
		if (!in_array($status, $inWorkStatuses, true))
		{
			return self::GROUP_RANK_MY_COMPLETED;
		}

		// Group 1 exists only in a project context: without the $cardGroupId guard
		// project-less tasks would match 0 === 0 and jump into it.
		return ($cardGroupId > 0 && $groupId === $cardGroupId)
			? self::GROUP_RANK_MY_PROJECT
			: self::GROUP_RANK_MY_OTHER;
	}

	/**
	 * @param list<int> $orderedIds
	 * @return list<array{ID: int, TITLE: string, STATUS: int}>
	 */
	private function hydrate(int $userId, array $orderedIds): array
	{
		if ($orderedIds === [])
		{
			return [];
		}

		$query = (new TaskQuery($userId))
			->setSelect(['ID', 'TITLE', 'REAL_STATUS'])
			->setWhere([
				'ID' => $orderedIds,
				'=ZOMBIE' => 'N',
			])
			->skipAccessCheck()
			->setLimit(count($orderedIds))
		;

		$byId = [];
		foreach ((new TaskList())->getList($query) as $row)
		{
			$byId[(int)$row['ID']] = $this->makeRow($row);
		}

		$result = [];
		foreach ($orderedIds as $id)
		{
			if (isset($byId[$id]))
			{
				$result[] = $byId[$id];
			}
		}

		return $result;
	}

	/**
	 * Foreign fill (groups 4-5): one access-checked recency pool over all statuses
	 * merged with the access-checked scored seed, classified and ordered in PHP.
	 *
	 * @param int[] $excludeIds
	 * @param array<int, array{score: float, lastAt: int}> $scoreMap
	 * @param array<int, array> $scoredRows scored-foreign raw rows (any status)
	 * @return list<array{ID: int, TITLE: string, STATUS: int}>
	 */
	private function rankForeign(
		int $userId,
		array $excludeIds,
		?string $searchQuery,
		int $limit,
		array $scoreMap,
		array $scoredRows,
		bool $editableOnly,
		?RelationKind $selectorKind,
		?int $parentId,
	): array
	{
		if ($limit <= 0)
		{
			return [];
		}

		$inWorkStatuses = Status::getInWorkStatuses();
		$recencyRows = $this->fetchForeignRecency(
			$userId,
			array_merge($inWorkStatuses, $this->getCompletedStatuses()),
			$excludeIds,
			$searchQuery,
			$this->foreignRecencyPoolLimit(count($scoredRows), $limit),
		);
		$rows = $this->filterSubtaskCandidates(
			$this->mergeRowsById($scoredRows, $recencyRows),
			$selectorKind,
			$parentId,
		);
		$rows = $this->filterEditableCandidates(
			$userId,
			$rows,
			$editableOnly,
		);

		$structs = [];
		foreach ($rows as $row)
		{
			$struct = $this->toForeignStruct($row, $scoreMap);
			$struct['GROUP_RANK'] = $this->classifyForeign($struct['STATUS'], $inWorkStatuses);
			$structs[] = $struct;
		}

		Collection::sortByColumn(
			$structs,
			[
				'GROUP_RANK' => SORT_ASC,
				'SCORE' => SORT_DESC,
				'LAST_AT' => SORT_DESC,
				'ACTIVITY_TS' => SORT_DESC,
			],
		);

		$structs = array_slice($structs, 0, $limit);

		return array_map(
			static fn (array $row): array => [
				'ID' => $row['ID'],
				'TITLE' => $row['TITLE'],
				'STATUS' => $row['STATUS'],
			],
			$structs,
		);
	}

	/**
	 * @param int[] $inWorkStatuses
	 */
	private function classifyForeign(int $status, array $inWorkStatuses): int
	{
		return in_array($status, $inWorkStatuses, true)
			? self::GROUP_RANK_FOREIGN_ACTIVE
			: self::GROUP_RANK_FOREIGN_COMPLETED;
	}

	/**
	 * Bounded recency pool of foreign tasks. No skipAccessCheck: foreign tasks pass
	 * the standard access filter, so history never grants access.
	 *
	 * @param int[] $statuses raw STATUS values
	 * @param int[] $excludeIds
	 * @return array<int, array> raw rows (ID, TITLE, REAL_STATUS, PARENT_ID, ACTIVITY_DATE)
	 */
	private function fetchForeignRecency(
		int $userId,
		array $statuses,
		array $excludeIds,
		?string $searchQuery,
		int $poolLimit,
	): array
	{
		$filter = [
			'REAL_STATUS' => array_values($statuses),
			'=ZOMBIE' => 'N',
		];
		$this->applyExclude($filter, $excludeIds);
		$this->applySearch($filter, $searchQuery);

		$query = (new TaskQuery($userId))
			->setSelect(['ID', 'TITLE', 'REAL_STATUS', 'PARENT_ID', 'ACTIVITY_DATE'])
			->setWhere($filter)
			->setOrder(['ACTIVITY_DATE' => 'DESC'])
			->setLimit($poolLimit)
		;

		return (new TaskList())->getList($query);
	}

	/**
	 * Scored foreign candidates, also through the standard access check: an
	 * interacted task the user can no longer read is filtered out here.
	 *
	 * @param int[] $foreignScoredIds
	 * @return array<int, array> raw rows (ID, TITLE, REAL_STATUS, PARENT_ID, ACTIVITY_DATE)
	 */
	private function fetchScoredForeign(int $userId, array $foreignScoredIds, ?string $searchQuery): array
	{
		$foreignScoredIds = array_values(array_unique(array_filter(array_map('intval', $foreignScoredIds))));
		if ($foreignScoredIds === [])
		{
			return [];
		}

		$filter = [
			'ID' => $foreignScoredIds,
			'=ZOMBIE' => 'N',
		];
		$this->applySearch($filter, $searchQuery);

		$query = (new TaskQuery($userId))
			->setSelect(['ID', 'TITLE', 'REAL_STATUS', 'PARENT_ID', 'ACTIVITY_DATE'])
			->setWhere($filter)
			->setLimit(count($foreignScoredIds))
		;

		return (new TaskList())->getList($query);
	}

	/**
	 * @param array<int, array> ...$rowSets
	 * @return array<int, array> unique rows, first occurrence per ID wins
	 */
	private function mergeRowsById(array ...$rowSets): array
	{
		$byId = [];
		foreach ($rowSets as $rows)
		{
			foreach ($rows as $row)
			{
				$id = (int)$row['ID'];
				if (!isset($byId[$id]))
				{
					$byId[$id] = $row;
				}
			}
		}

		return array_values($byId);
	}

	/**
	 * Own candidates bypass the regular read filter and therefore need this check
	 * before they are ranked or hydrated when the selector accepts only editable tasks.
	 *
	 * @param array<int, array> $candidates
	 * @return array<int, array>
	 */
	private function filterEditableCandidates(int $userId, array $candidates, bool $editableOnly): array
	{
		if (!$editableOnly || $candidates === [])
		{
			return $candidates;
		}

		$taskIds = array_map('intval', array_column($candidates, 'ID'));
		$rights = $this->taskRightService->getTaskRightsBatch(
			userId: $userId,
			taskIds: $taskIds,
			rules: ['edit' => ActionDictionary::TASK_ACTIONS['edit']],
		);

		return array_values(array_filter(
			$candidates,
			static fn (array $candidate): bool => (bool)($rights[(int)$candidate['ID']]['edit'] ?? false),
		));
	}

	/**
	 * A subtask selector may offer a free task or one already linked to its card,
	 * but never a task linked to another parent. This stays in PHP because the
	 * free-task condition covers most rows and is not selective in SQL.
	 *
	 * @param array<int, array> $candidates
	 * @return array<int, array>
	 */
	private function filterSubtaskCandidates(
		array $candidates,
		?RelationKind $selectorKind,
		?int $parentId,
	): array
	{
		if ($selectorKind !== RelationKind::Subtask || $parentId === null)
		{
			return $candidates;
		}

		return array_values(array_filter(
			$candidates,
			static function (array $candidate) use ($parentId): bool {
				$taskParentId = $candidate['PARENT_ID'] ?? null;

				return $taskParentId === null
					|| (int)$taskParentId === 0
					|| (int)$taskParentId === $parentId;
			},
		));
	}

	/**
	 * @param array<int, array{score: float, lastAt: int}> $scoreMap
	 * @return array{ID: int, TITLE: string, STATUS: int, SCORE: float, LAST_AT: int, ACTIVITY_TS: int}
	 */
	private function toForeignStruct(array $row, array $scoreMap): array
	{
		$taskId = (int)$row['ID'];

		$activityTs = ($row['ACTIVITY_DATE'] instanceof DateTime)
			? $row['ACTIVITY_DATE']->getTimestamp()
			: 0;

		return [
			'ID' => $taskId,
			'TITLE' => (string)($row['TITLE'] ?? ''),
			'STATUS' => (int)($row['REAL_STATUS'] ?? 0),
			'SCORE' => $scoreMap[$taskId]['score'] ?? 0.0,
			'LAST_AT' => $scoreMap[$taskId]['lastAt'] ?? 0,
			'ACTIVITY_TS' => $activityTs,
		];
	}

	private function makeRow(array $row): array
	{
		return [
			'ID' => (int)$row['ID'],
			'TITLE' => (string)($row['TITLE'] ?? ''),
			'STATUS' => (int)($row['REAL_STATUS'] ?? 0),
		];
	}

	private function ownPoolLimit(?string $searchQuery): int
	{
		$candidateLimit = RankingConfig::getCandidateLimit();

		return $this->isSearch($searchQuery)
			? min($candidateLimit, self::SEARCH_RESCORE_POOL)
			: $candidateLimit;
	}

	private function ownRecencyPoolLimit(?string $searchQuery, int $scoredCount, int $limit): int
	{
		return $this->recencyPoolLimit($this->ownPoolLimit($searchQuery), $scoredCount, $limit);
	}

	private function foreignRecencyPoolLimit(int $scoredCount, int $limit): int
	{
		$pool = min(RankingConfig::getCandidateLimit(), self::FOREIGN_RESCORE_POOL);

		return $this->recencyPoolLimit($pool, $scoredCount, $limit);
	}

	/**
	 * The recency query takes what the scored seed left of the pool, but never less
	 * than the display limit - a user whose history fills the pool still gets fresh
	 * tasks, and a freshly created project does not come back empty.
	 */
	private function recencyPoolLimit(int $pool, int $scoredCount, int $limit): int
	{
		return max(min($limit, $pool), $pool - $scoredCount);
	}

	private function isSearch(?string $searchQuery): bool
	{
		return $searchQuery !== null && $searchQuery !== '';
	}

	private function applyExclude(array &$filter, array $excludeIds): void
	{
		$excludeIds = array_values(array_unique(array_filter(array_map('intval', $excludeIds))));
		if ($excludeIds !== [])
		{
			$filter['!ID'] = $excludeIds;
		}
	}

	private function applySearch(array &$filter, ?string $searchQuery): void
	{
		if ($this->isSearch($searchQuery))
		{
			$filter['META::ID_OR_NAME'] = $searchQuery;
		}
	}

	/**
	 * @return int[] raw STATUS values treated as completed / closed (not in work)
	 */
	private function getCompletedStatuses(): array
	{
		return [
			Status::SUPPOSEDLY_COMPLETED,
			Status::COMPLETED,
			Status::DEFERRED,
			Status::DECLINED,
		];
	}
}
