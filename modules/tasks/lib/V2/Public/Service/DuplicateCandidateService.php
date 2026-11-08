<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Service;

use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Repository\DuplicateCandidateRepositoryInterface;

/**
 * Public entry point for the duplicate shortlist: unfinished tasks that a newly extracted
 * task could duplicate. The verdict itself is not made here, the search semantics are fixed
 * and live in the repository.
 */
class DuplicateCandidateService
{
	private const DEFAULT_LIMIT = 5;

	private readonly DuplicateCandidateRepositoryInterface $repository;

	public function __construct()
	{
		$this->repository = Container::getInstance()->getDuplicateCandidateRepository();
	}

	/**
	 * @return array<int, array{ID: int, TITLE: string, DESCRIPTION: string, CREATED_BY: int, RESPONSIBLE_ID: int, DEADLINE: ?string}>
	 */
	public function findCandidates(
		string $searchWords,
		int $initiatorId,
		?int $responsibleId = null,
		int $limit = self::DEFAULT_LIMIT,
	): array
	{
		return $this->repository->findCandidates($searchWords, $initiatorId, $responsibleId, $limit);
	}
}
