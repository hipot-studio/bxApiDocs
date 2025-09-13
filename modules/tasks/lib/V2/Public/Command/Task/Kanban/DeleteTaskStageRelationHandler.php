<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Command\Task\Kanban;

use Bitrix\Tasks\V2\Internal\Repository\TaskStageRepositoryInterface;

class DeleteTaskStageRelationHandler
{
	public function __construct(
		private readonly TaskStageRepositoryInterface $taskStageRepository,
	)
	{

	}
	public function __invoke(DeleteTaskStageRelationCommand $command): void
	{
		$this->taskStageRepository->deleteById(...$command->relationIds);
	}
}