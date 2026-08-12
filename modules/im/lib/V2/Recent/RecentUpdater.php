<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Recent;

use Bitrix\Im\Model\RecentTable;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\Tree\ChatTreeFilterFactory;
use Bitrix\Im\V2\Chat\Tree\ChatTreeScope;
use Bitrix\Im\V2\Recent\Internal\RecentItemCache;
use Bitrix\Main\Type\DateTime;

class RecentUpdater
{
	public function __construct(
		private readonly RecentItemCache $cache,
		private readonly ChatTreeFilterFactory $treeFilterFactory,
	) {}

	/**
	 * @param array{PREVIEW_SOURCE_CID:int,PREVIEW_SOURCE_MID:int}|null $previewSourceColumns
	 *        preview-source pointer to update on the same row; null leaves the pointer untouched.
	 */
	public function update(
		int $forUserId,
		?int $forChatId = null,
		?bool $unread = null,
		?int $markedId = null,
		?array $previewSourceColumns = null,
	): void
	{
		[$filter, $fields] = $this->prepareBaseFilterAndFields($forUserId, $unread, $markedId);
		if ($forChatId !== null)
		{
			$filter['=ITEM_CID'] = $forChatId;
		}
		if ($previewSourceColumns !== null)
		{
			$fields += $previewSourceColumns;
		}
		RecentTable::updateByFilter($filter, $fields);
		$this->cache->remove($forUserId, $forChatId);
	}

	public function setPinned(int $forUserId, Chat $chat, bool $pinned): void
	{
		$chatId = (int)$chat->getChatId();
		if ($chatId <= 0)
		{
			return;
		}

		RecentTable::updateByFilter(
			[
				'=USER_ID' => $forUserId,
				'=ITEM_CID' => $chatId,
			],
			[
				'PINNED' => $pinned ? 'Y' : 'N',
				'DATE_UPDATE' => new DateTime(),
			],
		);

		$this->cache->remove($forUserId, $chatId);
	}

	public function updateByTreeScope(int $forUserId, ChatTreeScope $scope): array
	{
		if (!$scope->isPossible())
		{
			return [];
		}

		$chatIds = $this->getUnreadRecentChatIdsByTreeScope($forUserId, $scope);
		if (empty($chatIds))
		{
			return [];
		}

		[$filter, $fields] = $this->prepareBaseFilterAndFields($forUserId, unread: false);
		$filter['=ITEM_CID'] = $chatIds;

		RecentTable::updateByFilter($filter, $fields);
		$this->cache->remove($forUserId);

		return $chatIds;
	}

	/**
	 * @return int[]
	 */
	private function getUnreadRecentChatIdsByTreeScope(
		int $forUserId,
		ChatTreeScope $scope,
	): array
	{
		$query = RecentTable::query()
			->setSelect(['ITEM_CID'])
			->where('USER_ID', $forUserId)
			->where('UNREAD', 'Y')
		;

		$this->treeFilterFactory->forTreeScope($scope)->apply($query);

		$rows = $query->fetchAll();

		return array_map('intval', array_column($rows, 'ITEM_CID'));
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
