<?php

namespace Bitrix\Im\V2\Controller\Recent;

use Bitrix\Im\V2\Controller\BaseController;
use Bitrix\Im\V2\Recent\RecentExternalChat;

class ExternalChat extends BaseController
{
	/**
	 * @restMethod im.v2.Recent.ExternalChat.tail
	 */
	public function tailAction(string $type, int $limit = 50, array $filter = []): ?array
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
		$recent = RecentExternalChat::getExternalChats($type, $limit, $filter);

		return $this->toRestFormatWithPaginationData([$recent], $limit, $recent->count());
	}
}
