<?php

namespace Bitrix\ImOpenLines\V2\Controller\Session;

use Bitrix\ImOpenLines\V2\Controller\BaseController;
use Bitrix\ImOpenLines\V2\Statistics\RatingListService;

class Rating extends BaseController
{
	/**
	 * @restMethod imopenlines.v2.Session.Rating.list
	 */
	public function listAction(
		?int $configId = null,
		array $configIdList = [],
		?int $operatorId = null,
		array $operatorIdList = [],
		?string $source = null,
		array $sourceList = [],
		?string $vote = null,
		?string $dateVoteFrom = null,
		?string $dateVoteTo = null,
		?string $hasVoteHead = null,
		int $offset = 0,
		int $limit = 50
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

		$operatorIds = !empty($operatorIdList) ? $operatorIdList : $operatorId;
		if ($operatorIds !== null)
		{
			$filter['operatorId'] = $operatorIds;
		}

		$sources = !empty($sourceList) ? $sourceList : $source;
		if ($sources !== null)
		{
			$filter['source'] = $sources;
		}

		if ($vote !== null)
		{
			$filter['vote'] = $vote;
		}

		if ($dateVoteFrom !== null)
		{
			$filter['dateVoteFrom'] = $dateVoteFrom;
		}

		if ($dateVoteTo !== null)
		{
			$filter['dateVoteTo'] = $dateVoteTo;
		}

		$hasVoteHeadValue = $this->parseBoolFilter($hasVoteHead);
		if ($hasVoteHeadValue !== null)
		{
			$filter['hasVoteHead'] = $hasVoteHeadValue;
		}

		$service = new RatingListService();
		$result = $service->getList($userId, $filter, $offset, $this->getLimit($limit));

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $result->getData();
	}
}
