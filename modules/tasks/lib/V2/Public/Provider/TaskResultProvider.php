<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Provider;

use Bitrix\Main\Provider\Params\PagerInterface;
use Bitrix\Tasks\V2\Internal\Access\Service\ResultRightService;
use Bitrix\Tasks\V2\Internal\Entity\Result;
use Bitrix\Tasks\V2\Internal\Entity\ResultCollection;
use Bitrix\Tasks\V2\Internal\Integration\Disk\Entity\DiskFile;
use Bitrix\Tasks\V2\Internal\Integration\Disk\Repository\DiskFileRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\TaskResultRepositoryInterface;

class TaskResultProvider
{
	public function __construct(
		private readonly ResultRightService $resultRightService,
		private readonly TaskResultRepositoryInterface $repository,
		private readonly DiskFileRepositoryInterface $diskFileRepository,
	)
	{
	}

	public function getResultById(int $resultId, int $userId): ?Result
	{
		$result = $this->repository->getById($resultId);
		if ($result === null)
		{
			return null;
		}

		$rights = $this->getRights([$resultId], $userId);
		$resultRights = $rights['rights'][$resultId] ?? null;

		$files = $this->diskFileRepository->getByIds((array)$result->fileIds);
		$resultFiles = $files->filter(static fn (DiskFile $file): bool => in_array($file->id, (array)$result->fileIds, true));

		return $result->cloneWith(['rights' => $resultRights, 'files' => $resultFiles]);
	}

	public function getResults(array $resultIds, int $userId): ResultCollection
	{
		$collection = $this->repository->getByIds($resultIds);

		return $this->enrichResults($collection, $userId);
	}

	public function getTaskResults(int $taskId, int $userId, ?PagerInterface $pager = null): ResultCollection
	{
		$collection = $this->repository->getByTask(
			taskId: $taskId,
			limit: $pager?->getLimit(),
			offset: $pager?->getOffset(),
		);

		return $this->enrichResults($collection, $userId);
	}

	public function getResultMessageMap(int $taskId): array
	{
		return $this->repository->getResultMessageMap(
			taskId: $taskId,
		);
	}

	protected function getRights(array $resultIds, int $userId): array
	{
		$rights = $this->resultRightService->getResultRightsBatch(
			userId: $userId,
			resultIds: $resultIds,
		);

		return ['rights' => $rights];
	}

	private function enrichResults(ResultCollection $collection, int $userId): ResultCollection
	{
		if ($collection->isEmpty())
		{
			return $collection;
		}

		$rights = $this->getRights($collection->getIdList(), $userId);

		$fileIds = [];

		foreach ($collection as $item)
		{
			$fileIds = [...$fileIds, ...($item->fileIds ?? [])];
		}

		$files = $this->diskFileRepository->getByIds(array_unique($fileIds));

		$results = new ResultCollection();
		foreach ($collection as $item)
		{
			$resultRights = $rights['rights'][$item->getId()] ?? null;
			$resultFiles = $files->filter(static fn (DiskFile $file): bool => in_array($file->id, (array)$item->fileIds, true));

			$results->add($item->cloneWith(['rights' => $resultRights, 'files' => $resultFiles]));
		}

		return $results;
	}
}
