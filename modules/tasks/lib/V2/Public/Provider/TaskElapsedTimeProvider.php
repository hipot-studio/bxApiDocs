<?php

namespace Bitrix\Tasks\V2\Public\Provider;

use Bitrix\Main\Provider\Params\PagerInterface;
use Bitrix\Tasks\V2\Internal\Access\Service\ElapsedTimeRightService;
use Bitrix\Tasks\V2\Internal\Entity\Task\ElapsedTime;
use Bitrix\Tasks\V2\Internal\Entity\Task\ElapsedTimeCollection;
use Bitrix\Tasks\V2\Internal\Repository\ElapsedTimeReadRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\ElapsedTimeRepositoryInterface;

class TaskElapsedTimeProvider
{
	public function __construct(
		private readonly ElapsedTimeRightService $elapsedTimeRightService,
		private readonly ElapsedTimeReadRepositoryInterface $elapsedTimeReadRepository,
		private readonly ElapsedTimeRepositoryInterface $elapsedTimeRepository,
	)
	{
	}

	public function getById(
		int $elapsedTimeId,
		int $userId,
	): ?ElapsedTime
	{
		$elapsedTime = $this->elapsedTimeReadRepository->getById(
			elapsedTimeId: $elapsedTimeId,
		);

		if ($elapsedTime === null)
		{
			return null;
		}

		return $this->enrichElapsedTimeCollection(new ElapsedTimeCollection($elapsedTime), $userId)->findOneById($elapsedTimeId);
	}

	public function getList(
		int $taskId,
		int $userId,
		PagerInterface $pager,
		array $order = ['ID' => 'DESC'],
	): ElapsedTimeCollection
	{
		$collection = $this->elapsedTimeReadRepository->getList(
			taskId: $taskId,
			limit: $pager->getLimit(),
			offset: $pager->getOffset(),
			order: $order,
		);

		return $this->enrichElapsedTimeCollection($collection, $userId);
	}

	public function getParticipantsContribution(int $taskId): array
	{
		return $this->elapsedTimeReadRepository->getUsersContribution($taskId);
	}

	public function getTimeSpentOnTask(int $taskId): int
	{
		return $this->elapsedTimeRepository->getSum($taskId);
	}

	public function getNumberOfElapsedTimes(int $taskId): int
	{
		return $this->elapsedTimeRepository->getCount($taskId);
	}

	protected function getRights(array $elapsedTimeIds, int $userId): array
	{
		$rights = $this->elapsedTimeRightService->getElapsedTimeRightsBatch(
			userId: $userId,
			elapsedTimeIds: $elapsedTimeIds,
		);

		return ['rights' => $rights];
	}

	private function enrichElapsedTimeCollection(ElapsedTimeCollection $collection, int $userId): ElapsedTimeCollection
	{
		if ($collection->isEmpty())
		{
			return $collection;
		}

		$rights = $this->getRights($collection->getIdList(), $userId);

		$elapsedTimeCollection = new ElapsedTimeCollection();
		foreach ($collection as $item)
		{
			$elapsedTimeRights = $rights['rights'][$item->getId()] ?? null;

			$elapsedTimeCollection->add($item->cloneWith(['rights' => $elapsedTimeRights]));
		}

		return $elapsedTimeCollection;
	}
}
