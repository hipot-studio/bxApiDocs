<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository;

interface DuplicateCandidateRepositoryInterface
{
	/**
	 * @return array<int, array{ID: int, TITLE: string, DESCRIPTION: string, CREATED_BY: int, RESPONSIBLE_ID: int, DEADLINE: ?string}>
	 */
	public function findCandidates(
		string $searchWords,
		int $initiatorId,
		?int $responsibleId,
		int $limit,
	): array;
}
