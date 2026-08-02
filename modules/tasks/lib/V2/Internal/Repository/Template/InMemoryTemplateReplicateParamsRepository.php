<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository\Template;

use Bitrix\Main\Type\Collection;
use Bitrix\Tasks\V2\Internal\Entity\Template\TemplateReplicateParams;

class InMemoryTemplateReplicateParamsRepository implements TemplateReplicateParamsRepositoryInterface
{
	private readonly TemplateReplicateParamsRepositoryInterface $repository;

	/** @var TemplateReplicateParams[] */
	private array $cacheByTaskId = [];

	/** @var TemplateReplicateParams[] */
	private array $cacheByTemplateId = [];

	/** @var array<int, int> */
	private array $taskIdByTemplateId = [];

	public function __construct(TemplateReplicateParamsRepository $repository)
	{
		$this->repository = $repository;
	}

	public function getByTaskId(int $taskId): ?TemplateReplicateParams
	{
		if (isset($this->cacheByTaskId[$taskId]))
		{
			return $this->cacheByTaskId[$taskId];
		}

		$templateReplicateParams = $this->repository->getByTaskId($taskId);
		if ($templateReplicateParams === null)
		{
			return null;
		}

		$this->addTaskCache($taskId, $templateReplicateParams);

		return $templateReplicateParams;
	}

	public function getByTaskIds(array $taskIds): array
	{
		Collection::normalizeArrayValuesByInt($taskIds, false);

		if ($taskIds === [])
		{
			return [];
		}

		$notCachedTaskIds = [];

		foreach ($taskIds as $taskId)
		{
			if (!isset($this->cacheByTaskId[$taskId]))
			{
				$notCachedTaskIds[] = $taskId;
			}
		}

		if ($notCachedTaskIds !== [])
		{
			foreach ($this->repository->getByTaskIds($notCachedTaskIds) as $taskId => $templateReplicateParams)
			{
				$this->addTaskCache($taskId, $templateReplicateParams);
			}
		}

		$result = [];

		foreach ($taskIds as $taskId)
		{
			if (isset($this->cacheByTaskId[$taskId]))
			{
				$result[$taskId] = $this->cacheByTaskId[$taskId];
			}
		}

		return $result;
	}

	public function getByTemplateId(int $templateId): ?TemplateReplicateParams
	{
		if (isset($this->cacheByTemplateId[$templateId]))
		{
			return $this->cacheByTemplateId[$templateId];
		}

		$templateReplicateParams = $this->repository->getByTemplateId($templateId);
		if ($templateReplicateParams === null)
		{
			return null;
		}

		$this->cacheByTemplateId[$templateId] = $templateReplicateParams;

		return $templateReplicateParams;
	}

	public function getByTemplateIds(array $templateIds): array
	{
		Collection::normalizeArrayValuesByInt($templateIds, false);

		if ($templateIds === [])
		{
			return [];
		}

		$notCachedTemplateIds = [];

		foreach ($templateIds as $templateId)
		{
			if (!isset($this->cacheByTemplateId[$templateId]))
			{
				$notCachedTemplateIds[] = $templateId;
			}
		}

		if ($notCachedTemplateIds !== [])
		{
			foreach ($this->repository->getByTemplateIds($notCachedTemplateIds) as $templateId => $templateReplicateParams)
			{
				$this->cacheByTemplateId[$templateId] = $templateReplicateParams;
			}
		}

		$result = [];

		foreach ($templateIds as $templateId)
		{
			if (isset($this->cacheByTemplateId[$templateId]))
			{
				$result[$templateId] = $this->cacheByTemplateId[$templateId];
			}
		}

		return $result;
	}

	public function invalidateByTemplateId(int $templateId): void
	{
		$taskId = $this->taskIdByTemplateId[$templateId] ?? null;

		unset(
			$this->cacheByTemplateId[$templateId],
			$this->taskIdByTemplateId[$templateId],
			$this->cacheByTaskId[$taskId],
		);
	}

	public function invalidateByTaskId(int $taskId): void
	{
		$templateId = $this->cacheByTaskId[$taskId]->templateId ?? null;

		unset(
			$this->taskIdByTemplateId[$templateId],
			$this->cacheByTaskId[$taskId],
		);
	}

	private function addTaskCache(int $taskId, TemplateReplicateParams $templateReplicateParams): void
	{
		$this->cacheByTaskId[$taskId] = $templateReplicateParams;

		if ($templateReplicateParams->templateId !== null)
		{
			$this->cacheByTemplateId[$templateReplicateParams->templateId] = $templateReplicateParams;
			$this->taskIdByTemplateId[$templateReplicateParams->templateId] = $taskId;
		}
	}
}
