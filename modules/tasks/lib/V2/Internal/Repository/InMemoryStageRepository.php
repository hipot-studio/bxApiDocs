<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository;

use Bitrix\Tasks\V2\Internal\Entity;

class InMemoryStageRepository implements StageRepositoryInterface
{
	private StageRepositoryInterface $stageRepository;

	private array $cache = [];
	/** @var Entity\StageCollection[] */
	private array $groupCache = [];
	/** @var int[] */
	private array $firstIdCache = [];

	public function __construct(StageRepository $stageRepository)
	{
		$this->stageRepository = $stageRepository;
	}

	public function getByGroupId(int $groupId): Entity\StageCollection
	{
		if (isset($this->groupCache[$groupId]))
		{
			return $this->groupCache[$groupId];
		}

		$stages = $this->stageRepository->getByGroupId($groupId);

		$this->groupCache[$groupId] = $stages;

		foreach ($stages as $stage)
		{
			$this->cache[$stage->id] = $stage;
		}

		return $this->groupCache[$groupId];
	}

	public function getById(int $id): ?Entity\Stage
	{
		if (!isset($this->cache[$id]))
		{
			$this->cache[$id] = $this->stageRepository->getById($id);
		}

		return $this->cache[$id];
	}

	public function getFirstIdByGroupId(int $groupId): ?int
	{
		if (isset($this->groupCache[$groupId]))
		{
			$this->firstIdCache[$groupId] = $this->groupCache[$groupId]->sort('sort')->getFirstEntity()?->getId();
		}

		if (isset($this->firstIdCache[$groupId]))
		{
			return $this->firstIdCache[$groupId];
		}

		$this->firstIdCache[$groupId] = $this->stageRepository->getFirstIdByGroupId($groupId);

		return $this->firstIdCache[$groupId];
	}
}
