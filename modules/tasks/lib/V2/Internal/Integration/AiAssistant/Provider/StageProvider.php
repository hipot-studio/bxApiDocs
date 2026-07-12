<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Provider;

use Bitrix\Tasks\V2\Internal\Service\Task\TaskStageService;

class StageProvider
{
	public function __construct(
		private readonly TaskStageService $taskStageService,
	)
	{
	}

	public function getTitlesByGroupId(int $groupId): array
	{
		if ($groupId <= 0)
		{
			return [];
		}

		$stages = $this->taskStageService->getStagesByGroupId($groupId);

		$result = [];
		foreach ($stages as $stage)
		{
			$id = (int)$stage->getId();
			if ($id > 0 && $stage->title !== null && $stage->title !== '')
			{
				$result[$id] = $stage->title;
			}
		}

		return $result;
	}
}
