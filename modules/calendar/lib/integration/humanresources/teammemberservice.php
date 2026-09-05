<?php

declare(strict_types=1);

namespace Bitrix\Calendar\Integration\HumanResources;

use Bitrix\HumanResources\Service\Container;
use Bitrix\HumanResources\Type\NodeEntityType;
use Bitrix\Main\Loader;

class TeamMemberService
{
	/**
	 * Returns user ids of direct members of the given team nodes.
	 * Flat membership only: sub-teams are not traversed.
	 *
	 * @param int[] $nodeIds
	 * @return int[]
	 */
	public function getDirectMemberUserIds(array $nodeIds): array
	{
		if (empty($nodeIds) || !$this->isModuleInstalled())
		{
			return [];
		}

		$nodeMemberService = Container::getNodeMemberService();

		$userIds = [];
		foreach ($nodeIds as $nodeId)
		{
			$nodeId = (int)$nodeId;
			if ($nodeId <= 0)
			{
				continue;
			}

			try
			{
				// Recalculation may run for a team that was just deleted/moved, or for a code that
				// points at a non-TEAM node; skip both instead of letting HR fatal on a missing node.
				$node = Container::getNodeRepository()->getById($nodeId);
				if ($node?->type !== NodeEntityType::TEAM)
				{
					continue;
				}

				$members = $nodeMemberService->getAllEmployees($nodeId, false);
			}
			catch (\Throwable)
			{
				continue;
			}

			foreach ($members as $member)
			{
				$userIds[] = (int)$member->entityId;
			}
		}

		return array_values(array_unique($userIds));
	}

	private function isModuleInstalled(): bool
	{
		return Loader::includeModule('humanresources');
	}
}
