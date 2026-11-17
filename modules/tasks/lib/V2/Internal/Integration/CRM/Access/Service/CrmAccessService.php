<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\CRM\Access\Service;

use Bitrix\Tasks\V2\Internal\Integration\CRM\Entity\CrmItem;
use Bitrix\Tasks\V2\Internal\Integration\CRM\Entity\CrmItemCollection;
use Bitrix\Tasks\V2\Internal\Integration\CRM\Entity\LinkedType;
use Bitrix\Tasks\V2\Internal\Integration\CRM\Repository\CrmItemRepositoryInterface;

class CrmAccessService
{
	public function __construct(
		private readonly CrmItemRepositoryInterface $crmItemRepository,
		private readonly CrmItemVisibilityService $crmItemVisibilityService,
	)
	{

	}

	public function getTasksCrmItems(array $taskIds, int $userId): CrmItemCollection
	{
		$itemMap = $this->crmItemRepository->getIdsByTaskIds($taskIds);
		$itemIds = array_merge(...$itemMap);
		$items = $this->crmItemRepository->getReadableByIds($itemIds, $userId);

		$collection = new CrmItemCollection();
		foreach ($itemMap as $taskId => $taskItemIds)
		{
			$crmItems =
				$items
					->filter(static fn (CrmItem $item): bool => in_array($item->id, $taskItemIds, true))
					->cloneWith(['linkedEntityId' => $taskId, 'linkedEntityType' => LinkedType::Task->value])
			;

			$collection->merge($crmItems);
		}

		return $collection;
	}

	public function canChangeCrmItems(array $ids, int $userId, int $taskId): bool
	{
		$current = $this->crmItemRepository->getIdsByTaskId($taskId);

		$changed = array_merge(
			array_diff($ids, $current),
			array_diff($current, $ids)
		);

		if (empty($changed))
		{
			return true;
		}

		$withAccess = $this->filterCrmItemsWithAccess($changed, $userId);

		return count($changed) === count($withAccess);
	}

	public function filterCrmItemsWithAccess(array $ids, int $userId): array
	{
		return $this->crmItemVisibilityService->filterReadable($ids, $userId);
	}

	public function filterByTask(array $ids, int $taskId): array
	{
		$crmItemIds = $this->crmItemRepository->getIdsByTaskId($taskId);

		return array_filter($ids, static fn (string $id): bool => in_array($id, $crmItemIds, true));
	}

	public function filterByTemplate(array $ids, int $templateId): array
	{
		$crmItemIds = $this->crmItemRepository->getIdsByTemplateId($templateId);

		return array_filter($ids, static fn (string $id): bool => in_array($id, $crmItemIds, true));
	}

}
