<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Provider;

use Bitrix\Tasks\V2\Internal\Entity\HistoryLog;
use Bitrix\Tasks\V2\Internal\Entity\HistoryLogCollection;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Exception\InvalidIdentifierException;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Provider\Mapper\TaskHistoryResponseMapper;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Provider\Trait\UsersTrait;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto\GetTaskHistoryDto;
use Bitrix\Tasks\V2\Internal\Service\TaskAnalytics\Enum\SignificantHistoryField;
use Bitrix\Tasks\V2\Internal\Repository\TaskHistoryRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\UserRepositoryInterface;

class TaskHistoryProvider
{
	use UsersTrait;

	public function __construct(
		private readonly TaskHistoryRepositoryInterface $taskHistoryRepository,
		private readonly UserRepositoryInterface $userRepository,
		private readonly TaskHistoryResponseMapper $historyResponseMapper,
	)
	{
	}

	/**
	 * @throws InvalidIdentifierException
	 */
	public function get(GetTaskHistoryDto $dto, int $userId): array
	{
		if ($dto->taskId === null || $dto->taskId <= 0)
		{
			throw new InvalidIdentifierException();
		}

		$limit = $dto->limit ?? GetTaskHistoryDto::LIMIT_DEFAULT;
		$offset = $dto->offset ?? 0;

		$historyLogs = $this->taskHistoryRepository->tail($dto->taskId, $offset, $limit + 1);

		$hasMore = $historyLogs->count() > $limit;
		if ($hasMore)
		{
			$historyLogs = new HistoryLogCollection(...array_slice($historyLogs->getEntities(), 0, $limit));
		}

		$userNames = $this->loadUserNames($historyLogs);

		return [
			'taskId' => $dto->taskId,
			'history' => $this->historyResponseMapper->mapFromEntityCollection($historyLogs, $userNames, $userId),
			'limit' => $limit,
			'offset' => $offset,
			'hasMore' => $hasMore,
		];
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

	private function loadUserNames(HistoryLogCollection $historyLogs): array
	{
		$userIdMap = [];

		/** @var HistoryLog $historyLog */
		foreach ($historyLogs as $historyLog)
		{
			if ($historyLog->userId !== null)
			{
				$userIdMap[$historyLog->userId] = true;
			}
		}

		return $this->userRepository->getNamesByIds(array_keys($userIdMap));
	}
}
