<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Chat\Tree;

use Bitrix\HumanResources\Service\Container;
use Bitrix\HumanResources\Type\RelationEntityType;
use Bitrix\Im\Model\ChatTable;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\Tree\Async\DescendantUnlinkBatch;
use Bitrix\Im\V2\Integration\HumanResources\Structure;
use Bitrix\Main\Loader;

/**
 * Maintains the tree invariant "a chat's department/team links are mirrored onto all of its ancestor
 * chats" (and, symmetrically, removed from descendants when dropped from an ancestor). Structure members
 * then reach every parent through that parent's own HR sync (add and remove), not through the member
 * push-up cascade.
 *
 * Idempotent: already-linked nodes are skipped and HR fires no relation event for a duplicate link/unlink,
 * so the recursion that re-enters this synchronizer on each new ancestor/descendant change terminates
 * naturally.
 */
class ChatTreeDepartmentSynchronizer
{
	private const DESCENDANT_BATCH_SIZE = 200;

	public function __construct(private readonly ChatAncestorIterator $ancestorIterator)
	{
	}

	public function syncToAncestors(Chat $chat): void
	{
		$childAccessCodes = (new Structure($chat))->getNodesAccessCodes();
		if (empty($childAccessCodes))
		{
			return;
		}

		foreach ($this->ancestorIterator->ancestorsOf($chat) as $ancestorChat)
		{
			$ancestorStructure = new Structure($ancestorChat);
			$accessCodesToLink = array_values(
				array_diff($childAccessCodes, $ancestorStructure->getNodesAccessCodes())
			);
			if (empty($accessCodesToLink))
			{
				continue;
			}

			$ancestorStructure->link($accessCodesToLink);
		}
	}

	/**
	 * Down-cascade complement of syncToAncestors: when a department node is unlinked from a chat, the tree
	 * invariant requires it gone from every descendant too. Processes ONE bounded batch of direct children
	 * (keyset-paginated by ID > $cursor) — the caller (queue receiver) re-runs with the returned lastId until
	 * the level is drained. Each unlink raises OnRelationDeleted(CHAT) on the child, which drops its members
	 * AND enqueues that child's own down-cascade, so deeper levels are walked via the queue, not here.
	 */
	public function unlinkFromDescendantsBatch(int $chatId, int $nodeId, int $cursor): DescendantUnlinkBatch
	{
		if ($chatId <= 0 || $nodeId <= 0 || !Loader::includeModule('humanresources'))
		{
			return new DescendantUnlinkBatch(false, $cursor);
		}

		$childChatIds = $this->directChildChatIdsBatch($chatId, $cursor, self::DESCENDANT_BATCH_SIZE);
		if (empty($childChatIds))
		{
			return new DescendantUnlinkBatch(false, $cursor);
		}

		Container::getNodeRelationService()->unlinkByEntityIdsAndNodeIdAndType(
			$nodeId,
			$childChatIds,
			RelationEntityType::CHAT,
		);

		return new DescendantUnlinkBatch(
			hasMore: count($childChatIds) === self::DESCENDANT_BATCH_SIZE,
			lastId: (int)max($childChatIds),
		);
	}

	/**
	 * @return int[] direct child chat ids with ID > $cursor, ascending, capped at $limit (keyset pagination)
	 */
	private function directChildChatIdsBatch(int $chatId, int $cursor, int $limit): array
	{
		$rows = ChatTable::query()
			->setSelect(['ID'])
			->where('PARENT_ID', $chatId)
			->where('ID', '>', $cursor)
			->setOrder(['ID' => 'ASC'])
			->setLimit($limit)
			->fetchAll()
		;

		return array_map(static fn (array $row): int => (int)$row['ID'], $rows);
	}
}
