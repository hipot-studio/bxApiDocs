<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository;

interface FlowMemberRepositoryInterface
{
	public function getDepartmentsOldIdsByType(int $flowId): array;
}
