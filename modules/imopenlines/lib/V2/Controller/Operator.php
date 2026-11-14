<?php

namespace Bitrix\ImOpenLines\V2\Controller;

use Bitrix\ImOpenLines\V2\Statistics\OperatorListService;

class Operator extends BaseController
{
	/**
	 * @restMethod imopenlines.v2.Operator.list
	 */
	public function listAction(
		?int $configId = null,
		array $configIdList = [],
		?int $userId = null,
		array $userIdList = [],
		?string $status = null,
		?string $hasFreeSlots = null,
		int $offset = 0,
		int $limit = 50
	): ?array
	{
		$currentUser = $this->getCurrentUser();
		$currentUserId = (int)$currentUser?->getId();

		$filter = [];

		$configIds = !empty($configIdList) ? $configIdList : $configId;
		if ($configIds !== null)
		{
			$filter['configId'] = $configIds;
		}

		$userIds = !empty($userIdList) ? $userIdList : $userId;
		if ($userIds !== null)
		{
			$filter['userId'] = $userIds;
		}

		if ($status !== null)
		{
			$filter['status'] = $status;
		}

		$hasFreeSlotsValue = $this->parseBoolFilter($hasFreeSlots);
		if ($hasFreeSlotsValue !== null)
		{
			$filter['hasFreeSlots'] = $hasFreeSlotsValue;
		}

		$service = new OperatorListService();
		$result = $service->getList($currentUserId, $filter, $offset, $this->getLimit($limit));

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $result->getData();
	}
}
