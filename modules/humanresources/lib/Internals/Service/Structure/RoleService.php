<?php
declare(strict_types=1);

namespace Bitrix\HumanResources\Internals\Service\Structure;

use Bitrix\HumanResources\Item;
use Bitrix\HumanResources\Type\NodeEntityType;
use Bitrix\HumanResources\Service\Container;

/**
 * Internal Role service
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

	/**
	 * Return deputy role object depending on node type.
	 */
	public function getDeputyRoleByNodeType(NodeEntityType $nodeType): ?Item\Role
	{
		$roleHelperService = Container::getRoleHelperService();

		$deputyId = match ($nodeType) {
			NodeEntityType::DEPARTMENT => $roleHelperService->getDeputyRoleId(),
			NodeEntityType::TEAM => $roleHelperService->getTeamDeputyRoleId(),
			default => null,
		};

		if (!$deputyId)
		{
			return null;
		}

		return $roleHelperService->getById($deputyId);
	}

	/**
	 * Return employee role object depending on node type.
	 */
	public function getEmployeeRoleByNodeType(NodeEntityType $nodeType): ?Item\Role
	{
		$roleHelperService = Container::getRoleHelperService();

		$employeeId = match ($nodeType) {
			NodeEntityType::DEPARTMENT => $roleHelperService->getEmployeeRoleId(),
			NodeEntityType::TEAM => $roleHelperService->getTeamEmployeeRoleId(),
			default => null,
		};

		if (!$employeeId)
		{
			return null;
		}

		return $roleHelperService->getById($employeeId);
	}
}
