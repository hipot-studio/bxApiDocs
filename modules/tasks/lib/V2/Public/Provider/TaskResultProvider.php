<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Provider;

use Bitrix\Main\Provider\Params\PagerInterface;
use Bitrix\Tasks\V2\Internal\Access\Service\ResultRightService;
use Bitrix\Tasks\V2\Internal\Entity\Result;
use Bitrix\Tasks\V2\Internal\Entity\ResultCollection;
use Bitrix\Tasks\V2\Internal\Integration\Disk\Entity\DiskFile;
use Bitrix\Tasks\V2\Internal\Integration\Disk\Repository\DiskFileRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Integration\Disk\Service\DiskArchiveLinkService;
use Bitrix\Tasks\V2\Internal\Repository\TaskResultRepositoryInterface;
use Bitrix\Tasks\V2\Public\Provider\Params\Result\TaskResultParams;

class TaskResultProvider
{
	/** Mirrors JS Limit.Results (tasks/install/js/tasks/v2/const/src/dictionary/limit.js) */
	public const CURRENT_USER_RESULT_MESSAGE_MAP_WINDOW = 3;

	public function __construct(
		private readonly ResultRightService $resultRightService,
		private readonly TaskResultRepositoryInterface $repository,
		private readonly DiskFileRepositoryInterface $diskFileRepository,
		private readonly DiskArchiveLinkService $diskArchiveLinkService,
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

		$archiveLink = $this->getArchiveLink($result);

		return $result->cloneWith([
			'rights' => $resultRights,
			'files' => $resultFiles,
			'archiveLink' => $archiveLink,
		]);
	}

	public function getResults(array $resultIds, int $userId): ResultCollection
	{
		$collection = $this->repository->getByIds($resultIds);

		return $this->enrichResults($collection, $userId);
	}

	public function getList(TaskResultParams $params, int $userId): ResultCollection
	{
		$resultCollection = $this->repository->getByStatements(
			$params->getSelect(),
			$params->getFilter(),
			$params->getSort(),
			$params->getLimit(),
			$params->getOffset(),
		);

		if ($resultCollection->isEmpty())
		{
			return $resultCollection;
		}

		$isNeedToRequestRights = $params->isInSelect('rights') ?? false;
		if ($isNeedToRequestRights)
		{
			$resultCollection = $this->addRights($resultCollection, $userId);
		}

		return $resultCollection;
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

	public function getCurrentUserResultMessageMap(int $taskId, int $userId): array
	{
		return $this->repository->getCurrentUserResultMessageMap(
			taskId: $taskId,
			userId: $userId,
			limit: self::CURRENT_USER_RESULT_MESSAGE_MAP_WINDOW,
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

		// Resolve archive links in a single batch to avoid N+1 calls.
		$resultIdsWithFiles = [];
		foreach ($collection as $item)
		{
			if (!empty($item->fileIds) && $item->getId() !== null)
			{
				$resultIdsWithFiles[] = $item->getId();
			}
		}

		$archiveLinkMap = $this->diskArchiveLinkService->getByResultIds($resultIdsWithFiles);

		$results = new ResultCollection();
		foreach ($collection as $item)
		{
			$resultRights = $rights['rights'][$item->getId()] ?? null;
			$resultFiles = $files->filter(static fn (DiskFile $file): bool => in_array($file->id, (array)$item->fileIds, true));

			$archiveLink = $archiveLinkMap[$item->getId()] ?? null;

			$results->add($item->cloneWith([
				'rights' => $resultRights,
				'files' => $resultFiles,
				'archiveLink' => $archiveLink,
			]));
		}

		return $results;
	}

	private function getArchiveLink(Result $result): ?string
	{
		if (empty($result->fileIds))
		{
			return null;
		}

		$resultId = $result->getId();
		if ($resultId === null)
		{
			return null;
		}

		return $this->diskArchiveLinkService->getByResultId($resultId);
	}

	private function addRights(ResultCollection $collection, int $userId): ResultCollection
	{
		$rights = $this->getRights($collection->getIdList(), $userId);

		$results = new ResultCollection();
		foreach ($collection as $item)
		{
			$resultRights = $rights['rights'][$item->getId()] ?? null;

			$results->add($item->cloneWith(['rights' => $resultRights]));
		}

		return $results;
	}
}
