<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Grid\History\Service;

use Bitrix\Tasks\V2\Internal\Integration\CRM\Service\CrmOwnerTypeService;

class CrmService
{
	public function __construct(
		private readonly CrmOwnerTypeService $crmOwnerTypeService,
	)
	{

	}

	public function fillCrm(array $crmItemIds): ?array
	{
		$crmItemIds = array_filter($crmItemIds, fn ($crmItemId) => is_string($crmItemId) && trim($crmItemId) !== '');

		if (empty($crmItemIds))
		{
			return null;
		}

		$crmItems = [];
		foreach ($crmItemIds as $crmItemId)
		{
			$typeWithId = explode('_', $crmItemId);

			if (count($typeWithId) !== 2)
			{
				continue;
			}

			[$type, $id] = $typeWithId;
			$id = (int)$id;

			$typeId = $this->crmOwnerTypeService->resolveId($type);

			if ($typeId === 0)
			{
				continue;
			}

			$crmItems[] = [
				'title' => $this->crmOwnerTypeService->getCaption($typeId, $id),
				'link' => $this->crmOwnerTypeService->getEntityPath($typeId, $id),
			];
		}

		return array_filter($crmItems, fn ($crmItem) => (string)($crmItem['title'] ?? '') !== '');
	}
}
