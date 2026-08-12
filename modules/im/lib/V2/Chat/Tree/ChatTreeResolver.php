<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Chat\Tree;

use Bitrix\Im\Model\ChatTable;
use Bitrix\Im\V2\Chat\Type\TypeRegistry;
use Bitrix\Im\V2\Common\Normalizer;
use Bitrix\Main\ORM\Query\Join;

final class ChatTreeResolver
{
	public function __construct(
		private readonly TypeRegistry $typeRegistry,
	) {}

	/**
	 * @param int[] $chatIds
	 * @param int[] $knownChatIds
	 * @return ResolvedChatNode[]
	 */
	public function resolveForUser(int $userId, array $chatIds, array $knownChatIds = []): array
	{
		if ($userId <= 0)
		{
			return [];
		}

		$knownChatIdsMap = array_fill_keys(Normalizer::toUniquePositiveIntegers($knownChatIds), true);
		$requestedChatIdsMap = $knownChatIdsMap;
		$pendingChatIds = [];
		foreach (Normalizer::toUniquePositiveIntegers($chatIds) as $chatId)
		{
			if (!isset($requestedChatIdsMap[$chatId]))
			{
				$pendingChatIds[$chatId] = $chatId;
			}
		}

		if (empty($pendingChatIds))
		{
			return [];
		}

		$resolved = [];

		for ($depth = 0; $depth <= ChatAncestorNavigator::DEFAULT_DEPTH && !empty($pendingChatIds); $depth++)
		{
			$currentChatIds = array_values($pendingChatIds);
			foreach ($currentChatIds as $chatId)
			{
				$requestedChatIdsMap[$chatId] = true;
			}

			$rows = $this->fetchAccessibleChatRows($currentChatIds, $userId);
			$pendingChatIds = [];

			foreach ($rows as $row)
			{
				$chatId = (int)($row['CHAT_ID'] ?? 0);
				if ($chatId <= 0)
				{
					continue;
				}

				$node = new ResolvedChatNode(
					chatId: $chatId,
					parentChatId: (int)($row['PARENT_ID'] ?? 0),
					isMuted: ($row['IS_MUTED'] ?? 'N') === 'Y',
					chatType: (string)($row['CHAT_TYPE'] ?? ''),
					entityType: isset($row['CHAT_ENTITY_TYPE']) ? (string)$row['CHAT_ENTITY_TYPE'] : null,
					// No recent row for this user (LEFT join miss) => chat is hidden from the recent list.
					isHidden: empty($row['RECENT_PRESENT']),
				);

				$resolved[$node->chatId] = $node;
			}

			foreach ($rows as $row)
			{
				$parentChatId = (int)($row['PARENT_ID'] ?? 0);
				$node = $resolved[(int)($row['CHAT_ID'] ?? 0)] ?? null;
				if (
					$node !== null
					&& $parentChatId > 0
					&& $this->requiresParentMembership($node)
					&& !isset($requestedChatIdsMap[$parentChatId])
				)
				{
					$pendingChatIds[$parentChatId] = $parentChatId;
				}
			}
		}

		return array_values($this->removeOrphanedNodes($resolved, $knownChatIdsMap));
	}

	/**
	 * @param int[] $chatIds
	 * @return array<int, array<string, mixed>>
	 */
	private function fetchAccessibleChatRows(array $chatIds, int $userId): array
	{
		if (empty($chatIds))
		{
			return [];
		}

		$query = ChatTable::query()
			->setSelect([
				'CHAT_ID' => 'ID',
				'CHAT_TYPE' => 'TYPE',
				'CHAT_ENTITY_TYPE' => 'ENTITY_TYPE',
				'PARENT_ID' => 'PARENT_ID',
				'IS_MUTED' => 'RELATION.NOTIFY_BLOCK',
				'RECENT_PRESENT' => 'RECENT.ITEM_CID',
			])
			->whereIn('ID', $chatIds)
			->withRelation($userId, Join::TYPE_INNER)
			->withRecent($userId)
		;

		return $query->fetchAll();
	}

	/**
	 * @param array<int, ResolvedChatNode> $resolved
	 * @param array<int, bool> $knownChatIdsMap
	 * @return array<int, ResolvedChatNode>
	 */
	private function removeOrphanedNodes(array $resolved, array $knownChatIdsMap): array
	{
		$result = [];
		foreach ($resolved as $chatId => $node)
		{
			if (
				$node->parentChatId > 0
				&& $this->requiresParentMembership($node)
				&& !$this->isAncestorChainComplete($node, $resolved, $knownChatIdsMap)
			)
			{
				continue;
			}

			$result[$chatId] = $node;
		}

		return $result;
	}

	/**
	 * @param array<int, ResolvedChatNode> $resolved
	 * @param array<int, bool> $knownChatIdsMap
	 */
	private function isAncestorChainComplete(ResolvedChatNode $node, array $resolved, array $knownChatIdsMap): bool
	{
		$current = $node;

		for ($depth = 0; $current->parentChatId > 0 && $depth < ChatAncestorNavigator::DEFAULT_DEPTH; $depth++)
		{
			if (!$this->requiresParentMembership($current))
			{
				return true;
			}

			if (isset($knownChatIdsMap[$current->parentChatId]))
			{
				return true;
			}

			$parent = $resolved[$current->parentChatId] ?? null;
			if ($parent === null)
			{
				return false;
			}

			$current = $parent;
		}

		return $current->parentChatId === 0;
	}

	private function requiresParentMembership(ResolvedChatNode $node): bool
	{
		return $this->typeRegistry->requiresParentMembership($node->chatType, $node->entityType);
	}
}
