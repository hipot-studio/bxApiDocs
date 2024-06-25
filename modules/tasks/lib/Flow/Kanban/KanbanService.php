<?php

namespace Bitrix\Tasks\Flow\Kanban;

use Bitrix\Tasks\Flow\Kanban\Stages\CompletedStage;
use Bitrix\Tasks\Flow\Kanban\Stages\NewStage;
use Bitrix\Tasks\Flow\Kanban\Stages\ProgressStage;
use Bitrix\Tasks\Flow\Kanban\Stages\ReviewStage;
use Bitrix\Tasks\Internals\Log\Logger;
use Throwable;

class KanbanService
{
	protected KanbanCommand $command;
	
	public function add(KanbanCommand $command): void
	{
		$this->command = $command;
		
		$stages = $this->getStages();
		foreach ($stages as $stage)
		{
			try
			{
				$result = $stage->save();
			}
			catch (Throwable $t)
			{
				Logger::logThrowable($t);
				continue;
			}

			if (!$result->isSuccess())
			{
				Logger::log($result->getErrorMessages());
			}
		}
	}

	/**
	 * @return AbstractStage[]
	 */
	protected function getStages(): array
	{
		return [
			new NewStage($this->command->projectId, $this->command->ownerId),
			new ProgressStage($this->command->projectId, $this->command->ownerId),
			new ReviewStage($this->command->projectId, $this->command->ownerId),
			new CompletedStage($this->command->projectId, $this->command->ownerId),
		];
	}
}