<?php

declare(strict_types=1);

namespace Bitrix\Calendar\Integration\HumanResources;

use Bitrix\HumanResources\Builder\Structure\Filter\Column\IdFilter;
use Bitrix\HumanResources\Builder\Structure\Filter\Column\Node\NodeTypeFilter;
use Bitrix\HumanResources\Builder\Structure\Filter\NodeFilter;
use Bitrix\HumanResources\Builder\Structure\Filter\SelectionCondition\Node\NodeAccessFilter;
use Bitrix\HumanResources\Builder\Structure\NodeDataBuilder;
use Bitrix\HumanResources\Type\NodeEntityType;
use Bitrix\HumanResources\Type\StructureAction;
use Bitrix\Main\Loader;

class TeamAccessService
{
	private const NODE_BATCH_SIZE = 500;

	/**
	 * Checks whether the user is allowed to view the given team (structure node).
	 * Deny-by-default: missing module, non-positive id, unknown/inaccessible node -> false.
	 */
	public function canViewTeam(int $nodeId, int $userId): bool
	{
		return in_array($nodeId, $this->getViewableTeamIds([$nodeId], $userId), true);
	}

	/**
	 * @param int[] $nodeIds
	 * @return int[]
	 */
	public function getViewableTeamIds(array $nodeIds, int $userId): array
	{
		$nodeIds = array_values(array_unique(array_filter(
			array_map('intval', $nodeIds),
			static fn (int $nodeId): bool => $nodeId > 0,
		)));
		if (empty($nodeIds) || $userId <= 0 || !Loader::includeModule('humanresources'))
		{
			return [];
		}

		return $this->getViewableTeamIdsWithLegacyApi($nodeIds, $userId);
	}

	private function getViewableTeamIdsWithLegacyApi(array $nodeIds, int $userId): array
	{
		if (
			!class_exists(NodeDataBuilder::class)
			|| !class_exists(NodeAccessFilter::class)
		)
		{
			return [];
		}

		$viewableNodeIndex = [];
		foreach (array_chunk($nodeIds, self::NODE_BATCH_SIZE) as $nodeIdBatch)
		{
			try
			{
				$viewableNodeIds = (new NodeDataBuilder())
					->addFilter(new NodeFilter(
						idFilter: IdFilter::fromIds($nodeIdBatch),
						entityTypeFilter: NodeTypeFilter::fromNodeType(NodeEntityType::TEAM),
						active: true,
						accessFilter: new NodeAccessFilter(
							StructureAction::ViewAction,
							userId: $userId,
						),
					))
					->getAll()
					->getIds()
				;
				$viewableNodeIndex += array_fill_keys($viewableNodeIds, true);
			}
			catch (\Throwable)
			{
				continue;
			}
		}

		return array_values(array_filter(
			$nodeIds,
			static fn (int $nodeId): bool => isset($viewableNodeIndex[$nodeId]),
		));
	}
}
