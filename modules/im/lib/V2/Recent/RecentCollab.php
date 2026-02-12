<?php

namespace Bitrix\Im\V2\Recent;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Recent\Query\RecentFilter;
use Bitrix\Im\V2\Recent\Query\RecentParams;
use Bitrix\Im\V2\Service\Locator;

class RecentCollab extends Recent
{
	public static function getCollabs(int $limit, array $filter = []): self
	{
		$userId = Locator::getContext()->getUserId();

		$filter['itemType'] = Chat::IM_TYPE_COLLAB;
		$filter['userId'] = $userId;

		$queryFilter = new RecentFilter($filter);
		$recentParams = new RecentParams(
			filter: $queryFilter,
			limit: $limit,
			order: Recent::getOrder($userId)
		);

		$recentEntities = static::getRecentEntities($recentParams);
		return static::initByArray($recentEntities);
	}
}
