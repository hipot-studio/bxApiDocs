<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Controller;

use Bitrix\Im\V2\Recent\Query\RecentFilter;
use Bitrix\Im\V2\Recent\Query\RecentParams;
use Bitrix\Im\V2\Recent\RecentProvider;
use Bitrix\Main\Engine\AutoWire\ExactParameter;
use Bitrix\Main\Engine\CurrentUser;

class Recent extends BaseController
{
	private const ALLOWED_FILTERS = [
		'lastMessageDate' => true,
		'recentSection' => true,
		'parentId' => true,
		'unread' => true
	];

	public function getAutoWiredParameters()
	{
		return array_merge([
			new ExactParameter(
				RecentParams::class,
				'params',
				function ($className, CurrentUser $currentUser, array $filter = [], int $limit = 50): ?RecentParams {
					return $this->getRecentParams((int)$currentUser->getId(), $filter, $limit);
				}
			),
		], parent::getAutoWiredParameters());
	}

	/**
	 * @restMethod im.v2.Recent.tail
	 */
	public function tailAction(
		RecentProvider $recentProvider,
		?RecentParams $params,
	): ?array
	{
		if ($params === null)
		{
			return null;
		}

		$recent = $recentProvider->getList($params);

		return $this->toRestFormatWithPaginationData([$recent], $params->limit, $recent->count());
	}

	private function getRecentParams(int $userId, array $filter = [], int $limit = 50): ?RecentParams
	{
		if (isset($filter['lastMessageDate']))
		{
			$filter['lastMessageDate'] = $this->getDateOrSetError($filter['lastMessageDate']);
			if ($filter['lastMessageDate'] === null)
			{
				return null;
			}
		}

		$filter = array_intersect_key($filter, self::ALLOWED_FILTERS);
		$filter['userId'] = $userId;

		return new RecentParams(
			filter: RecentFilter::fromArray($filter),
			limit: $this->getLimit($limit),
			order: \Bitrix\Im\V2\Recent\Recent::getOrder($userId),
		);
	}
}
