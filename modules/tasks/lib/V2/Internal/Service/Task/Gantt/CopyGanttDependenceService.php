<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Task\Gantt;

use Bitrix\Tasks\Internals\DataBase\Tree\LinkExistsException;
use Bitrix\Tasks\V2\Internal\Exception\Task\DescendentException;
use Bitrix\Tasks\V2\Internal\Repository\GanttLinkRepositoryInterface;

class CopyGanttDependenceService
{
	public function __construct(
		private readonly GanttDependenceService $ganttDependenceService,
		private readonly GanttLinkRepositoryInterface $ganttLinkRepository,
	)
	{

	}
	public function copy(int $fromTaskId, int $toTaskId, int $userId): void
	{
		$links = $this->ganttLinkRepository->getTaskLinks($fromTaskId);

		foreach ($links as $link)
		{
			$newLink = $link->cloneWith([
				'taskId' => $toTaskId,
				'creatorId' => $userId,
			]);

			try
			{
				$this->ganttDependenceService->add($newLink);
			}
			catch (LinkExistsException|DescendentException)
			{
			}
		}
	}
}
