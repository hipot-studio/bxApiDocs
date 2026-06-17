<?php

declare(strict_types=1);

namespace Bitrix\HumanResources\Integration\AiAssistant\Tools\Department;

use Bitrix\HumanResources\Access\Permission\PermissionDictionary;
use Bitrix\HumanResources\Integration\AiAssistant\Tools\NodeBaseTool;
use Bitrix\HumanResources\Internals\Service\Container as InternalContainer;
use Bitrix\HumanResources\Type\NodeEntityType;

/**
 * Get employees who belong to more than one department.
 *
 * @see \Bitrix\HumanResources\Rest\Controller\Employee::multiDepartmentAction — REST analog
 * @see \Bitrix\HumanResources\Controller\Structure\Node\Member::getMultipleUsersMapAction — ajax controller
 */
class GetMultiDepartmentEmployeesTool extends NodeBaseTool
{
	protected NodeEntityType $type = NodeEntityType::DEPARTMENT;
	protected string $permissionId = PermissionDictionary::HUMAN_RESOURCES_STRUCTURE_VIEW;

	public function getName(): string
	{
		return 'get_multi_department_employees';
	}

	public function getDescription(): string
	{
		return 'Get list of employees who belong to more than one department (multi-department employees). Returns employee names, positions, and their departments.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => (object)[],
			'additionalProperties' => false,
		];
	}

	public function execute(int $userId, ...$args): string
	{
		try
		{
			$multiDept = InternalContainer::getNodeMemberRepository()->getMultipleNodeMembers(
				NodeEntityType::DEPARTMENT,
			);

			if (empty($multiDept))
			{
				return 'No employees belong to more than one department.';
			}

			$allNodeIds = array_unique(array_merge(...array_values($multiDept)));
			$nodeCollection = InternalContainer::getNodeRepository()->findAllByIds(nodeIds: $allNodeIds);
			$nodesById = [];
			foreach ($nodeCollection as $node)
			{
				$nodesById[$node->id] = $node;
			}

			$userService = InternalContainer::getUserService();
			$usersById = [];
			foreach (InternalContainer::getUserRepository()->getByIds(array_keys($multiDept)) as $user)
			{
				$usersById[$user->id] = $user;
			}

			$lines = [];
			foreach ($multiDept as $uid => $nodeIds)
			{
				$user = $usersById[$uid] ?? null;
				if (!$user)
				{
					continue;
				}

				$name = $userService->getUserName($user);
				$deptNames = [];
				foreach ($nodeIds as $nodeId)
				{
					$node = $nodesById[$nodeId] ?? null;
					$deptNames[] = $node ? $node->name : "id:{$nodeId}";
				}

				$lines[] = "{$name} (id:{$user->id}, departments: " . implode(', ', $deptNames) . ')';
			}

			$count = count($lines);

			return "Found {$count} multi-department employees:\n" . implode("\n", $lines) . '.';
		}
		catch (\Exception $e)
		{
			$this->logException('Error getting multi-department employees: ' . $e->getMessage());

			return 'Error getting multi-department employees.';
		}
	}
}
