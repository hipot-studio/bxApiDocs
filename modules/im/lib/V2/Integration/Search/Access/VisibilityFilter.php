<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\Search\Access;

use Bitrix\Im\Model\ChatTable;
use Bitrix\Im\Model\RelationTable;
use Bitrix\Im\V2\Chat\Access\ParentChainFilterFactory;
use Bitrix\Im\V2\Chat\Access\SingleLevelAccessFilterFactory;
use Bitrix\Im\V2\Chat\Tree\TreeOrigin;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;

/**
 * Answers which of the given chats the user may read, in one query.
 *
 * Assembled from the very bricks the lists of the messenger already use: 'relation OR open type' of
 * the single level filter plus the parent chain of the chat tree. The action rights filter
 * Chat\Filter::filterByPermissions() is not reusable here - it counts a role without relation as -1
 * and knows nothing of the open chat policy.
 */
class VisibilityFilter
{
	public function __construct(
		private readonly SingleLevelAccessFilterFactory $singleLevelFilterFactory,
		private readonly ParentChainFilterFactory $parentChainFilterFactory,
	)
	{}

	/**
	 * @param int[] $chatIds
	 * @return int[] Identifiers of the visible chats.
	 */
	public function filterVisible(array $chatIds, int $userId): array
	{
		if ($chatIds === [] || $userId <= 0)
		{
			return [];
		}

		$query = ChatTable::query()->setSelect(['ID'])->whereIn('ID', $chatIds);

		$query->registerRuntimeField(new Reference(
			'RELATION',
			RelationTable::class,
			Join::on('this.ID', 'ref.CHAT_ID')->where('ref.USER_ID', $userId),
			['join_type' => Join::TYPE_LEFT],
		));
		$query->where($this->singleLevelFilterFactory->forUser($userId)->toConditionTree());
		$this->parentChainFilterFactory->forUser($userId, TreeOrigin::forChat())->apply($query);

		return array_map(static fn (array $row): int => (int)$row['ID'], $query->fetchAll());
	}
}
