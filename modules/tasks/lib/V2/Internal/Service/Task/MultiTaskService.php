<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Task;

use Bitrix\Main\Type\Collection;
use Bitrix\Tasks\V2\Internal\Entity\Task;
use Bitrix\Tasks\V2\Internal\Entity\TaskCollection;
use Bitrix\Tasks\V2\Internal\Entity\UserCollection;
use Bitrix\Tasks\V2\Internal\Repository\RelatedTaskRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Copy\Config\CopyConfig;

class MultiTaskService
{
	public function __construct(
		private readonly CopyTaskService $copyTaskService,
		private readonly RelatedTaskRepositoryInterface $relatedTaskRepository,
	)
	{

	}

	public function add(Task $rootTask, array $multiResponsibleIds, int $userId): TaskCollection
	{
		$collection = new TaskCollection();

		Collection::normalizeArrayValuesByInt($multiResponsibleIds, false);

		if (empty($multiResponsibleIds))
		{
			return $collection;
		}

		$subTaskFields = [
			'parent' => ['id' => $rootTask->getId()],
			'replicate' => false,
			'isMultitask' => false,
		];

		$copyConfig = new CopyConfig(
			userId: $userId,
			withSubTasks: false,
			withCheckLists: true,
			withAttachments: true,
			withRelatedTasks: true,
			withReminders: true,
			withGanttLinks: true,
		);

		foreach ($multiResponsibleIds as $responsibleId)
		{
			if ($responsibleId === $rootTask->responsible?->getId())
			{
				continue;
			}

			$subTaskFields['responsible'] = ['id' => $responsibleId];
			$subTask = $rootTask->cloneWith($subTaskFields);

			$subTask = $this->copyTaskService->copy($subTask, $copyConfig);
			$collection->add($subTask);
		}

		return $collection;
	}
}
