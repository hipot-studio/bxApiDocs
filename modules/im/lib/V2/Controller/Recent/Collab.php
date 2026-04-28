<?php

namespace Bitrix\Im\V2\Controller\Recent;

use Bitrix\Im\V2\Controller\BaseController;
use Bitrix\Im\V2\Recent\RecentCollab;

class Collab extends BaseController
{

	/**
	 * @restMethod im.v2.Recent.Collab.tail
	 */
	public function tailAction(int $limit = 50, array $filter = []): ?array
	{
		if (isset($filter['lastMessageDate']))
		{
			$filter['lastMessageDate'] = $this->getDateOrSetError($filter['lastMessageDate']);
			if ($filter['lastMessageDate'] === null)
			{
				return null;
			}
		}

		$limit = $this->getLimit($limit);
		$filter['parentId'] = 0;
		$recent = RecentCollab::getCollabs($limit, $filter);

		return $this->toRestFormatWithPaginationData([$recent], $limit, $recent->count());
	}
}
