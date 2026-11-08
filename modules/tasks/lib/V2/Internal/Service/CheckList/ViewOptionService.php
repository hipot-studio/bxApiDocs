<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\CheckList;

use Bitrix\Tasks\V2\Internal\Entity\CheckList\Type;
use Bitrix\Tasks\V2\Internal\Repository\CheckListViewOptionRepositoryInterface;

class ViewOptionService
{
	public function __construct(
		private readonly CheckListViewOptionRepositoryInterface $repository,
	)
	{
	}

	public function isCompletedHidden(Type $entityType, int $userId): bool
	{
		return !$this->repository->getShowCompleted($entityType, $userId);
	}

	public function setCompletedHidden(Type $entityType, int $userId, bool $hide): void
	{
		$this->repository->setShowCompleted($entityType, $userId, !$hide);
	}
}
