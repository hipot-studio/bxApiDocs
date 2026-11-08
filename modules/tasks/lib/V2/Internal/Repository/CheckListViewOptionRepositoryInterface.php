<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository;

use Bitrix\Tasks\V2\Internal\Entity\CheckList\Type;

interface CheckListViewOptionRepositoryInterface
{
	public function getShowCompleted(Type $entityType, int $userId): bool;

	public function setShowCompleted(Type $entityType, int $userId, bool $show): void;
}
