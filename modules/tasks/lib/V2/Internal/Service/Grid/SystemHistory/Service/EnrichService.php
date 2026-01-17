<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Grid\SystemHistory\Service;

use Bitrix\Tasks\V2\Internal\Entity\SystemHistoryLogCollection;
use Bitrix\Tasks\V2\Internal\Service\Grid\SystemHistory\Service\ErrorService;
use Bitrix\Tasks\V2\Internal\Service\Grid\SystemHistory\Service\TaskIdUtilityService;
use Bitrix\Tasks\Slider\Path\TaskPathMaker;

class EnrichService
{
	public function __construct(
		private readonly ErrorService $errorService,
		private readonly TaskIdUtilityService $taskIdUtilityService,
	)
	{

	}

	public function enrich(SystemHistoryLogCollection $systemHistoryLogCollection, int $userId): SystemHistoryLogCollection
	{
		foreach ($systemHistoryLogCollection as $systemHistoryLog)
		{
			$systemHistoryLog->errors = $this->errorService->fillErrors($systemHistoryLog->errors);

			$relatedTaskId = $this->taskIdUtilityService->unpackId($systemHistoryLog->message);

			if ($relatedTaskId !== null) {
				$systemHistoryLog->link = TaskPathMaker::getPath([
					'user_id' => $userId,
					'action' => 'view',
					'task_id' => $relatedTaskId,
				]);
			}
		}

		return $systemHistoryLogCollection;
	}
}
