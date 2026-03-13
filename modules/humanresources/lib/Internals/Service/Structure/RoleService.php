<?php
declare(strict_types=1);

namespace Bitrix\HumanResources\Internals\Service\Structure;

use Bitrix\HumanResources\Item;
use Bitrix\HumanResources\Type\NodeEntityType;
use Bitrix\HumanResources\Service\Container;

/**
 * Public Role service
 */
class RoleService
{
	/**
	 * Return head role object depending on node type.
	 */
	public function getHeadRoleByNodeType(NodeEntityType $nodeType): ?Item\Role
	{
		$roleHelperService = Container::getRoleHelperService();

		$headId = match ($nodeType) {
			NodeEntityType::DEPARTMENT => $roleHelperService->getHeadRoleId(),
			NodeEntityType::TEAM => $roleHelperService->getTeamHeadRoleId(),
			default => null,
		};

		if (!$headId)
		{
			return null;
		}

		return $roleHelperService->getById($headId);
	}
}