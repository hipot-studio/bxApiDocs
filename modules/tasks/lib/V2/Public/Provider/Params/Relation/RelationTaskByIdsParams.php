<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Provider\Params\Relation;

class RelationTaskByIdsParams
{
	public function __construct(
		public array $taskIds,
		public int $userId,
		public bool $withCompleted = true,
		public bool $withSubTasks = true,
	)
	{

	}
}
