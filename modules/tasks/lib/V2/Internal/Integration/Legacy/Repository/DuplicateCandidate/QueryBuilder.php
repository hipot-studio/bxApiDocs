<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Legacy\Repository\DuplicateCandidate;

use Bitrix\Tasks\Internals\Task\Status;
use Bitrix\Tasks\Provider\Query\TaskQuery;

class QueryBuilder
{
	private const PAYLOAD_SELECT = ['ID', 'TITLE', 'DESCRIPTION', 'CREATED_BY', 'RESPONSIBLE_ID', 'DEADLINE'];

	public function buildQuery(
		string $searchExpression,
		int $initiatorId,
		?int $responsibleId,
		int $limit,
	): TaskQuery
	{
		$responsibleIds = [$initiatorId];
		if ($responsibleId > 0)
		{
			$responsibleIds[] = $responsibleId;
		}

		// '::LOGIC' is the meta-key TaskFilterBuilder reads; a plain 'LOGIC' is silently
		// dropped and the group collapses into AND. One entry per field inside the group:
		// repeating '=RESPONSIBLE_ID' collapses into a single condition and the group
		// stops matching anything
		$roles = [
			'::LOGIC' => 'OR',
			['=CREATED_BY' => $initiatorId],
			['=RESPONSIBLE_ID' => array_values(array_unique($responsibleIds))],
		];

		return (new TaskQuery($initiatorId))
			->setParam('SEARCH_TASK_ONLY', 'Y')
			->setSelect(self::PAYLOAD_SELECT)
			->setWhere([
				'*FULL_SEARCH_INDEX' => $searchExpression,
				'<STATUS' => Status::SUPPOSEDLY_COMPLETED,
				$roles,
			])
			->setGroupBy('ID')
			->setOrder(['ID' => 'DESC'])
			->setLimit($limit)
		;
	}
}
