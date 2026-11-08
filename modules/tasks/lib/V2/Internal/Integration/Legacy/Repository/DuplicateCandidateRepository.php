<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Legacy\Repository;

use Bitrix\Tasks\Internals\SearchIndex;
use Bitrix\Tasks\Provider\TaskList;
use Bitrix\Tasks\V2\Internal\Integration\Legacy\Repository\DuplicateCandidate\QueryBuilder;
use Bitrix\Tasks\V2\Internal\Repository\DuplicateCandidateRepositoryInterface;

/**
 * Shortlist of unfinished tasks that a newly extracted task could duplicate.
 *
 * The verdict itself is not made here: this is a search with fixed semantics - full-text
 * match by significant words, the initiator as creator or the expected assignee as
 * responsible.
 *
 */
class DuplicateCandidateRepository implements DuplicateCandidateRepositoryInterface
{
	public function __construct(
		private readonly QueryBuilder $queryBuilder,
	)
	{
	}

	public function findCandidates(
		string $searchWords,
		int $initiatorId,
		?int $responsibleId,
		int $limit,
	): array
	{
		$searchWords = trim($searchWords);
		if ($searchWords === '' || $initiatorId <= 0)
		{
			return [];
		}

		// prepareStringToSearch() drops every word shorter than the fulltext min token size, so a
		// title built of two-letter abbreviations or emoji yields an empty expression. Without this
		// guard the '*FULL_SEARCH_INDEX' condition would vanish from the query and the search would
		// degrade into "last unfinished tasks of the initiator"
		$searchExpression = SearchIndex::prepareStringToSearch($searchWords);
		if ($searchExpression === '')
		{
			return [];
		}

		$query = $this->queryBuilder->buildQuery(
			$searchExpression,
			$initiatorId,
			$responsibleId,
			max(1, $limit),
		);

		$candidates = [];
		foreach ((new TaskList())->getList($query) as $task)
		{
			$candidates[] = [
				'ID' => (int)$task['ID'],
				'TITLE' => (string)($task['TITLE'] ?? ''),
				'DESCRIPTION' => (string)($task['DESCRIPTION'] ?? ''),
				'CREATED_BY' => (int)($task['CREATED_BY'] ?? 0),
				'RESPONSIBLE_ID' => (int)($task['RESPONSIBLE_ID'] ?? 0),
				'DEADLINE' => isset($task['DEADLINE']) ? (string)$task['DEADLINE'] : null,
			];
		}

		return $candidates;
	}
}
