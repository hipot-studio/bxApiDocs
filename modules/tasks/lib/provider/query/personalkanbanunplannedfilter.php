<?php

namespace Bitrix\Tasks\Provider\Query;

final class PersonalKanbanUnplannedFilter
{
	public readonly array $ownerStageIds;

	public function __construct(
		public readonly int $newStageId,
		array $ownerStageIds,
	)
	{
		$this->ownerStageIds = array_values(array_unique(array_map('intval', $ownerStageIds)));
	}
}
