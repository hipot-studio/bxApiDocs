<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Recent;

use Bitrix\Im\V2\Recent\Internal\RecentItemCache;
use Bitrix\Main\Type\DateTime;
use Bitrix\Im\Model\RecentTable;
use Bitrix\Im\V2\Chat;

class RecentUpdater
{
	public function __construct(
		private readonly RecentItemCache $cache,
	) {}

	public function update(
		int $forUserId,
		?int $forChatId = null,
		?bool $unread = null,
		?int $markedId = null,
	): void
	{
		[$filter, $fields] = $this->prepareBaseFilterAndFields($forUserId, $unread, $markedId);
		if ($forChatId !== null)
		{
			$filter['=ITEM_CID'] = $forChatId;
		}
		RecentTable::updateByFilter($filter, $fields);
		$this->cache->remove($forUserId, $forChatId);
	}

	public function updateByType(
		int $forUserId,
		Chat\Type $forType,
		?bool $unread = null,
		?int $markedId = null,
	): void
	{
		[$filter, $fields] = $this->prepareBaseFilterAndFields($forUserId, $unread, $markedId);

		if ($forType->entityType)
		{
			$chatIds = $this->getUnreadChatIdsByType($forUserId, $forType);
			if (empty($chatIds))
			{
				return;
			}

			$filter['=ITEM_CID'] = $chatIds;
		}
		else
		{
			$filter['=ITEM_TYPE'] = $forType->literal;
		}

		RecentTable::updateByFilter($filter, $fields);
		$this->cache->remove($forUserId);
	}

	private function getUnreadChatIdsByType(int $forUserId, Chat\Type $forType): array
	{
		$chatIds = RecentTable::query()
			->setSelect(['ITEM_CID'])
			->where('USER_ID', $forUserId)
			->where('UNREAD', 'Y')
			->where('ITEM_TYPE', $forType->literal)
			->where('CHAT.ENTITY_TYPE', $forType->entityType)
			->fetchAll()
		;

		return array_map('intval', array_column($chatIds, 'ITEM_CID'));
	}

	private function prepareBaseFilterAndFields(int $forUserId, ?bool $unread = null, ?int $markedId = null): array
	{
		$fields = [];
		$filter = ['=USER_ID' => $forUserId];
		if ($unread !== null)
		{
			if (!$unread)
			{
				$fields['MARKED_ID'] = 0;
			}
			elseif ($markedId)
			{
				$fields['MARKED_ID'] = $markedId;
			}
			$fields['UNREAD'] = $unread ? 'Y' : 'N';
			$filter['=UNREAD'] = $unread ? 'N' : 'Y';
		}
		$fields['DATE_UPDATE'] = new DateTime();

		return [$filter, $fields];
	}
}
