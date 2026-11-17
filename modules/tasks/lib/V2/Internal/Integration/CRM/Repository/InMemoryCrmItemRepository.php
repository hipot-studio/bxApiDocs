<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\CRM\Repository;

use Bitrix\Tasks\V2\Internal\Integration\CRM\Dto\CrmItemPreviewResult;
use Bitrix\Tasks\V2\Internal\Integration\CRM\Entity\CrmItemCollection;

class InMemoryCrmItemRepository implements CrmItemRepositoryInterface
{
	private CrmItemRepositoryInterface $crmItemRepository;

	private array $idCache = [];
	private array $templateIdCache = [];

	public function __construct(CrmItemRepository $crmItemRepository)
	{
		$this->crmItemRepository = $crmItemRepository;
	}

	public function getIdsByTaskId(int $taskId): array
	{
		if (!isset($this->idCache[$taskId]))
		{
			$this->idCache[$taskId] = $this->crmItemRepository->getIdsByTaskId($taskId);
		}

		return $this->idCache[$taskId];
	}

	public function getIdsByTemplateId(int $templateId): array
	{
		if (!isset($this->templateIdCache[$templateId]))
		{
			$this->templateIdCache[$templateId] = $this->crmItemRepository->getIdsByTemplateId($templateId);
		}

		return $this->templateIdCache[$templateId];
	}

	public function getIdsByTaskIds(array $taskIds): array
	{
		$result = [];
		$notStoredIds = [];

		foreach ($taskIds as $taskId)
		{
			if (isset($this->idCache[$taskId]))
			{
				$result[$taskId] = $this->idCache[$taskId];
			}
			else
			{
				$notStoredIds[] = $taskId;
				$result[$taskId] = [];
			}
		}

		if (!empty($notStoredIds))
		{
			$idMap = $this->crmItemRepository->getIdsByTaskIds($notStoredIds);
			$idMap = array_filter($idMap);
			foreach ($idMap as $taskId => $ids)
			{
				$this->idCache[$taskId] = $ids;
				$result[$taskId] = $ids;
			}
		}

		return $result;
	}

	public function getReadableByIds(array $ids, int $userId): CrmItemCollection
	{
		return $this->crmItemRepository->getReadableByIds($ids, $userId);
	}

	public function getPreviewByIds(array $ids, int $userId, int $limit): CrmItemPreviewResult
	{
		return $this->crmItemRepository->getPreviewByIds($ids, $userId, $limit);
	}

	public function invalidate(int $taskId): void
	{
		unset($this->idCache[$taskId]);
	}
}
