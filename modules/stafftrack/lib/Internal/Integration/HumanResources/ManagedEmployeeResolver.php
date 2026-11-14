<?php

namespace Bitrix\StaffTrack\Internal\Integration\HumanResources;

use Bitrix\HumanResources\Type\NodeMemberRole;

/**
 * Node\UserService::isManagerForEmployee() over departments, answered for a whole set of employees at once:
 * at most two reads per role of the manager, however many employees are asked about.
 *
 * The check pairs a membership of the manager with one of the employee in the same department or below it, and
 * holds when the role of the manager has a priority above zero and not below the one of the employee. Its strict
 * branch for one department falls through to that one, so the heads of a department manage each other, and so do
 * its deputies. A department carries only the roles of a department, ranked by the structure head 100, deputy 90,
 * employee 0: a head manages every member of his subtree, a deputy every deputy and employee of his.
 */
class ManagedEmployeeResolver
{
	private ManagedEmployeeGateway $gateway;

	public function __construct(?ManagedEmployeeGateway $gateway = null)
	{
		$this->gateway = $gateway ?? new ManagedEmployeeGateway();
	}

	/**
	 * A read that fails adds nobody: the check asked about each employee alone answered no on a failure too.
	 *
	 * @param int[] $employeeIds
	 * @return int[] those of $employeeIds the manager manages, in their order
	 */
	public function resolve(int $managerUserId, array $employeeIds): array
	{
		$employeeIds = IdNormalizer::normalize($employeeIds);
		if ($managerUserId <= 0 || empty($employeeIds))
		{
			return [];
		}

		$managedMap = [];
		foreach ([NodeMemberRole::Head, NodeMemberRole::DeputyHead] as $managerRole)
		{
			$unresolvedIds = array_values(
				array_filter($employeeIds, static fn (int $employeeId): bool => !isset($managedMap[$employeeId])),
			);
			if (empty($unresolvedIds))
			{
				break;
			}

			$departmentIds = $this->gateway->getDepartmentIdsByRole($managerUserId, $managerRole);
			if (empty($departmentIds))
			{
				continue;
			}

			$managedIds = $this->gateway->getMemberIdsWithin(
				$unresolvedIds,
				$departmentIds,
				self::getManagedRoles($managerRole),
			);
			$managedMap += array_flip($managedIds);
		}

		return array_values(
			array_filter($employeeIds, static fn (int $employeeId): bool => isset($managedMap[$employeeId])),
		);
	}

	/**
	 * @return NodeMemberRole[]
	 */
	private static function getManagedRoles(NodeMemberRole $managerRole): array
	{
		return match ($managerRole)
		{
			NodeMemberRole::Head => [NodeMemberRole::Head, NodeMemberRole::DeputyHead, NodeMemberRole::Employee],
			NodeMemberRole::DeputyHead => [NodeMemberRole::DeputyHead, NodeMemberRole::Employee],
			default => [],
		};
	}
}
