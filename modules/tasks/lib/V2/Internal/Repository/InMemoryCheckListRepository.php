<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository;

use Bitrix\Tasks\V2\Internal\Entity;

class InMemoryCheckListRepository implements CheckListRepositoryInterface
{
	private CheckListRepositoryInterface $checkListRepository;

	private array $cache = [];
	private array $idsCache = [];
	private array $attachmentCache = [];

	public function __construct(CheckListRepository $checkListRepository)
	{
		$this->checkListRepository = $checkListRepository;
	}

	public function getByEntities(array $entityIds, Entity\CheckList\Type $type): Entity\CheckList
	{
		$resultCollection = new Entity\CheckList();
		$nonCachedEntityIds = [];

		foreach ($entityIds as $entityId)
		{
			$key = "{$entityId}_{$type->value}";

			if (isset($this->cache[$key]))
			{
				$resultCollection->merge($this->cache[$key]);
			}
			else
			{
				$nonCachedEntityIds[] = $entityId;
			}
		}

		if (!empty($nonCachedEntityIds))
		{
			$nonCachedCheckLists = $this->checkListRepository->getByEntities($nonCachedEntityIds, $type);

			if (!$nonCachedCheckLists->isEmpty())
			{
				$resultCollection->merge($nonCachedCheckLists);

				$itemsByEntityId = [];

				/** @var Entity\CheckList\CheckListItem $item */
				foreach ($nonCachedCheckLists as $item)
				{
					if ($item->entityId === null)
					{
						continue;
					}

					$itemsByEntityId[$item->entityId][] = $item;
				}

				foreach ($itemsByEntityId as $entityId => $items)
				{
					$key = "{$entityId}_{$type->value}";

					$this->cache[$key] = new Entity\CheckList(...$items);
				}
			}

			foreach ($nonCachedEntityIds as $entityId)
			{
				$key = "{$entityId}_{$type->value}";

				if (!isset($this->cache[$key]))
				{
					$this->cache[$key] = new Entity\CheckList();
				}
			}
		}

		return $resultCollection;
	}

	public function getByEntity(int $entityId, Entity\CheckList\Type $type): Entity\CheckList
	{
		$key = "{$entityId}_{$type->value}";
		if (!isset($this->cache[$key]))
		{
			$this->cache[$key] = $this->checkListRepository->getByEntity($entityId, $type);
		}

		return $this->cache[$key];
	}

	public function getIdsByEntity(int $entityId, Entity\CheckList\Type $type): array
	{
		$key = "{$entityId}_{$type->value}";
		if (!isset($this->idsCache[$key]))
		{
			$this->idsCache[$key] = $this->checkListRepository->getIdsByEntity($entityId, $type);
		}

		return $this->idsCache[$key];
	}

	public function getAttachmentIdsByEntity(int $entityId, Entity\CheckList\Type $type): array
	{
		$key = "{$entityId}_{$type->value}";
		if (!isset($this->attachmentCache[$key]))
		{
			$this->attachmentCache[$key] = $this->checkListRepository->getAttachmentIdsByEntity($entityId, $type);
		}

		return $this->attachmentCache[$key];
	}
}
