<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\CRM\Repository;

use Bitrix\Tasks\V2\Internal\Integration\CRM\Entity\CrmItemCollection;

class InMemoryCrmItemRepository implements CrmItemRepositoryInterface
{
	private CrmItemRepositoryInterface $crmItemRepository;

	private array $idCache = [];
	private CrmItemCollection $itemCache;

	public function __construct(CrmItemRepository $crmItemRepository)
	{
		$this->crmItemRepository = $crmItemRepository;
		$this->itemCache = new CrmItemCollection();
	}

	public function getIdsByTaskId(int $taskId): array
	{
		if (!isset($this->idCache[$taskId]))
		{
			$this->idCache[$taskId] = $this->crmItemRepository->getIdsByTaskId($taskId);
		}

		return $this->idCache[$taskId];
	}

	public function getByIds(array $ids): CrmItemCollection
	{
		$crmItems = CrmItemCollection::mapFromIds(ids: $ids);
		$stored = $this->itemCache->findAllByIds($ids);

		$notStoredIds = $crmItems->diff($stored)->getIdList();

		if (empty($notStoredIds))
		{
			return $stored;
		}

		$crmItems = $this->crmItemRepository->getByIds($notStoredIds);

		$this->itemCache->merge($crmItems);

		return $crmItems;
	}
}