<?php

declare(strict_types=1);

namespace Bitrix\Calendar\Integration\HumanResources;

use Bitrix\HumanResources\Public\Service\Container;
use Bitrix\HumanResources\Type\NodeEntityType;
use Bitrix\Main\Loader;
use Bitrix\Main\UserTable;

class TeamMemberService
{
	private const NODE_BATCH_SIZE = 500;

	/**
	 * Returns user ids of direct members of the given team nodes.
	 * Flat membership only: sub-teams are not traversed.
	 *
	 * @param int[] $nodeIds
	 * @return int[]
	 */
	public function getDirectMemberUserIds(array $nodeIds): array
	{
		$nodeIds = array_values(array_unique(array_filter(
			array_map('intval', $nodeIds),
			static fn (int $nodeId): bool => $nodeId > 0,
		)));
		if (empty($nodeIds) || !$this->isModuleInstalled())
		{
			return [];
		}

		$nodeMemberService = Container::getNodeMemberService();
		$userIds = [];
		foreach (array_chunk($nodeIds, self::NODE_BATCH_SIZE) as $nodeIdBatch)
		{
			try
			{
				$query = UserTable::query()
					->setSelect(['ID'])
					->setDistinct()
					->addOrder('ID')
				;
				$nodeMemberService->injectUserNodeSubquery(
					query: $query,
					nodeTypes: NodeEntityType::TEAM,
					active: true,
					nodeIds: $nodeIdBatch,
				);
				$result = $query->exec();
				while ($user = $result->fetch())
				{
					$userIds[(int)$user['ID']] = (int)$user['ID'];
				}
			}
			catch (\Throwable)
			{
				continue;
			}
		}

		return array_values($userIds);
	}

	private function isModuleInstalled(): bool
	{
		return Loader::includeModule('humanresources');
	}
}
