<?php

namespace Bitrix\ImOpenLines\V2\Controller;

use Bitrix\ImOpenLines\V2\Statistics\StatService;

class Stat extends BaseController
{
	/**
	 * @restMethod imopenlines.v2.Stat.get
	 */
	public function getAction(
		?string $dateFrom = null,
		?string $dateTo = null,
		?int $configId = null,
		array $configIdList = [],
		?string $source = null,
		array $sourceList = [],
		?int $operatorId = null,
		array $operatorIdList = []
	): ?array
	{
		$currentUser = $this->getCurrentUser();
		$userId = (int)$currentUser?->getId();

		$filter = [];

		$configIds = !empty($configIdList) ? $configIdList : $configId;
		if ($configIds !== null)
		{
			$filter['configId'] = $configIds;
		}

		$sources = !empty($sourceList) ? $sourceList : $source;
		if ($sources !== null)
		{
			$filter['source'] = $sources;
		}

		$operatorIds = !empty($operatorIdList) ? $operatorIdList : $operatorId;
		if ($operatorIds !== null)
		{
			$filter['operatorId'] = $operatorIds;
		}

		$service = new StatService();
		$result = $service->getStat($userId, $dateFrom, $dateTo, $filter);

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $result->getData();
	}
}
