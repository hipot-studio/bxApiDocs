<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Chat\Tree;

use Bitrix\Im\Model\MessageUnreadTable;
use Bitrix\Im\Model\RecentTable;
use Bitrix\Im\Model\RelationTable;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\ORM\Query\Query;

readonly class SubtreeUnreadFilter
{
	public function __construct(
		private ChatAncestorNavigator $navigator,
		private int $userId,
		private int $maxDepth = ChatAncestorNavigator::DEFAULT_DEPTH,
	) {}

	public function apply(Query $query): void
	{
		$filter = Query::filter()->logic('OR');

		$this->addMessageCounterConditions($filter);
		$this->addUnreadFlagConditions($filter);

		$query->where($filter);
	}

	private function addMessageCounterConditions(ConditionTree $filter): void
	{
		$origin = TreeOrigin::forUnreadCounters();

		for ($depth = 0; $depth <= $this->maxDepth; $depth++)
		{
			$source = MessageUnreadTable::query()
				->where('USER_ID', $this->userId)
				->where('IS_MUTED', 'N')
			;

			$levels = $this->navigator->joinAncestors(
				$source,
				origin: $origin,
				depth: $depth,
				joinType: Join::TYPE_INNER,
			);
			$target = end($levels);

			if ($depth > 0)
			{
				$source->where($target->idExpression, '>', 0);
				$this->requireUnmutedAncestors($source, array_slice($levels, 1));
			}

			$source->setSelect([$target->idExpression]);
			$filter->whereIn('ITEM_CID', $source);
		}
	}

	private function addUnreadFlagConditions(ConditionTree $filter): void
	{
		$filter->where(
			Query::filter()
				->where('UNREAD', true)
				->where('RELATION.NOTIFY_BLOCK', 'N')
		);

		for ($depth = 1; $depth <= $this->maxDepth; $depth++)
		{
			$source = RecentTable::query()
				->where('USER_ID', $this->userId)
				->where('UNREAD', true)
				->where('RELATION.NOTIFY_BLOCK', 'N')
			;

			$levels = $this->navigator->joinAncestors(
				$source,
				origin: TreeOrigin::forChat('CHAT'),
				depth: $depth,
				joinType: Join::TYPE_INNER,
			);
			$target = end($levels);

			$source->where($target->idExpression, '>', 0);
			$this->requireUnmutedAncestors($source, array_slice($levels, 1));
			$source->setSelect([$target->idExpression]);
			$filter->whereIn('ITEM_CID', $source);
		}
	}

	/**
	 * @param TreeLevel[] $ancestorLevels
	 */
	private function requireUnmutedAncestors(Query $query, array $ancestorLevels): void
	{
		foreach ($ancestorLevels as $level)
		{
			$relAlias = "UNREAD_REL_{$this->userId}_{$level->depth}";

			$query->registerRuntimeField(
				$relAlias,
				(new Reference(
					$relAlias,
					RelationTable::class,
					Join::on('ref.CHAT_ID', "this.{$level->idExpression}")
						->where('ref.USER_ID', $this->userId),
				))->configureJoinType(Join::TYPE_INNER),
			);
			$query->where("{$relAlias}.NOTIFY_BLOCK", 'N');
		}
	}
}
