<?php

declare(strict_types=1);

namespace Bitrix\Calendar\Integration\HumanResources;

use Bitrix\HumanResources\Access\Model\NodeModel;
use Bitrix\HumanResources\Access\StructureAccessController;
use Bitrix\HumanResources\Access\StructureActionDictionary;
use Bitrix\Main\Access\Exception\UnknownActionException;
use Bitrix\Main\Loader;

class TeamAccessService
{
	/**
	 * Checks whether the user is allowed to view the given team (structure node).
	 * Deny-by-default: missing module, non-positive id, unknown/inaccessible node -> false.
	 */
	public function canViewTeam(int $nodeId, int $userId): bool
	{
		if ($nodeId <= 0 || $userId <= 0 || !Loader::includeModule('humanresources'))
		{
			return false;
		}

		try
		{
			return (new StructureAccessController($userId))->check(
				StructureActionDictionary::ACTION_TEAM_VIEW,
				NodeModel::createFromId($nodeId),
			);
		}
		catch (UnknownActionException)
		{
			return false;
		}
	}
}
