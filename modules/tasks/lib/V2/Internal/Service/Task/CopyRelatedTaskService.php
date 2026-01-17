<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Task;

use Bitrix\Tasks\V2\Internal\Repository\RelatedTaskRepositoryInterface;

class CopyRelatedTaskService
{
	public function __construct(
		private readonly RelatedTaskService $relatedTaskService,
		private readonly RelatedTaskRepositoryInterface $relatedTaskRepository,
	)
	{
	}

	public function copy(int $fromTaskId, int $toTaskId, int $userId): void
	{
		$relatedTaskIds = $this->relatedTaskRepository->getRelatedTaskIds($fromTaskId);

		if (empty($relatedTaskIds))
		{
			return;
		}

		$this->relatedTaskService->add($toTaskId, $relatedTaskIds, $userId);
	}
}
