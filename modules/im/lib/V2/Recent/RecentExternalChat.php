<?php

namespace Bitrix\Im\V2\Recent;

use Bitrix\Im\V2\Recent\Query\RecentFilter;
use Bitrix\Im\V2\Recent\Query\RecentParams;
use Bitrix\Im\V2\Service\Locator;

class RecentExternalChat extends Recent
{
	public static function getExternalChats(string $type, int $limit, array $filter = []): self
	{
		$userId = Locator::getContext()->getUserId();

		$filter['recentSection'] = $type;
		$filter['userId'] = $userId;

		$queryFilter = RecentFilter::fromArray($filter);
		$recentParams = new RecentParams(
			filter: $queryFilter,
			limit: $limit,
			order: Recent::getOrder($userId)
		);

		$recentEntities = static::getRecentEntities($recentParams);
		return static::initByArray($recentEntities);
	}
}
