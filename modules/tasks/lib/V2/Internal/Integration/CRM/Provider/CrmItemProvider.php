<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\CRM\Provider;

use Bitrix\Main\Loader;
use Bitrix\Tasks\V2\Internal\Integration\CRM\Access\Service\CrmAccessService;
use Bitrix\Tasks\V2\Internal\Integration\CRM\Dto\CrmItemPreviewResult;
use Bitrix\Tasks\V2\Internal\Integration\CRM\Entity\CrmItemCollection;
use Bitrix\Tasks\V2\Internal\Integration\CRM\Normalizer\CrmItemIdNormalizer;
use Bitrix\Tasks\V2\Internal\Integration\CRM\Repository\CrmItemRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Integration\CRM\Sorter\CrmItemIdSorter;

class CrmItemProvider
{
	public function __construct(
		private readonly CrmAccessService $crmAccessService,
		private readonly CrmItemRepositoryInterface $crmItemRepository,
		private readonly CrmItemIdSorter $crmItemIdSorter,
		private readonly CrmItemIdNormalizer $crmItemIdNormalizer,
	)
	{

	}

	public function getByIds(array $ids, int $taskId, int $userId): CrmItemCollection
	{
		if (!Loader::includeModule('crm'))
		{
			return new CrmItemCollection();
		}

		$ids = $this->crmAccessService->filterByTask($ids, $taskId);

		return $this->crmItemRepository->getReadableByIds($ids, $userId);
	}

	public function getByIdsByTemplateId(array $ids, int $templateId, int $userId): CrmItemCollection
	{
		if (!Loader::includeModule('crm'))
		{
			return new CrmItemCollection();
		}

		$ids = $this->crmAccessService->filterByTemplate($ids, $templateId);

		return $this->crmItemRepository->getReadableByIds($ids, $userId);
	}

	public function getPreviewByIds(array $ids, int $userId, int $limit): CrmItemPreviewResult
	{
		if (!Loader::includeModule('crm'))
		{
			return new CrmItemPreviewResult(new CrmItemCollection(), 0);
		}

		$ids = $this->crmItemIdNormalizer->getUniqueIds($ids);
		$ids = $this->crmItemIdSorter->sortIds($ids);

		return $this->crmItemRepository->getPreviewByIds($ids, $userId, $limit);
	}
}
