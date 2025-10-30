<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\CRM\Provider;

use Bitrix\Main\Loader;
use Bitrix\Tasks\V2\Internal\Integration\CRM\Entity\CrmItemCollection;
use Bitrix\Tasks\V2\Internal\Integration\CRM\Access\Service\CrmAccessService;
use Bitrix\Tasks\V2\Internal\Integration\CRM\Repository\CrmItemRepositoryInterface;

class CrmItemProvider
{
	public function __construct(
		private readonly CrmAccessService $crmAccessService,
		private readonly CrmItemRepositoryInterface $crmItemRepository,
	)
	{

	}

	public function getByIds(array $ids, int $taskId, int $userId): CrmItemCollection
	{
		if (!Loader::includeModule('crm'))
		{
			return new CrmItemCollection();
		}

		$ids = $this->filterByTask($ids, $taskId);
		$ids = $this->crmAccessService->filterCrmItemsWithAccess($ids, $userId);

		return $this->crmItemRepository->getByIds($ids);
	}

	private function filterByTask(array $ids, int $taskId): array
	{
		$crmItemIds = $this->crmItemRepository->getIdsByTaskId($taskId);

		return array_filter($ids, static fn (string $id): bool => in_array($id, $crmItemIds, true));
	}
}