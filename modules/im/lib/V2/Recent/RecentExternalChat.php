<?php

namespace Bitrix\Im\V2\Recent;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Recent\Query\RecentFilter;
use Bitrix\Im\V2\Recent\Query\RecentParams;
use Bitrix\Im\V2\Service\Locator;
use Bitrix\Main\Engine\Response\Converter;

class RecentExternalChat extends Recent
{
	public static function getExternalChats(string $type, int $limit, array $filter = []): self
	{
		$userId = Locator::getContext()->getUserId();

		$filter['itemType'] = Chat::IM_TYPE_EXTERNAL;
		$filter['entityType'] = self::getEntityTypeByType($type);
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

	protected static function getEntityTypeByType(string $type): string
	{
		return (new Converter(Converter::TO_SNAKE | Converter::TO_UPPER))->process($type);
	}
}
