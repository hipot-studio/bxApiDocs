<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Provider;

use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Provider\Trait\UsersTrait;
use Bitrix\Tasks\V2\Internal\Service\TaskAnalytics\Enum\SignificantHistoryField;
use Bitrix\Tasks\V2\Internal\Repository\TaskHistoryRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\UserRepositoryInterface;

class TaskHistoryProvider
{
	use UsersTrait;

	public function __construct(
		private readonly TaskHistoryRepositoryInterface $taskHistoryRepository,
		private readonly UserRepositoryInterface $userRepository,
	)
	{
	}

	public function getSignificantHistory(int $taskId, int $limit): array
	{
		$history = $this->taskHistoryRepository->tailByFields(
			$taskId,
			SignificantHistoryField::values(),
			0,
			$limit,
		);

		if (empty($history))
		{
			return [];
		}

		return $this->appendUserNames($history);
	}
}
