<?php

namespace Bitrix\StaffTrack\Internal\Integration\HumanResources;

class BranchManagerResolver
{
	private StructureBranchGateway $gateway;

	/** @var array<int, int[]> */
	private array $memo = [];

	public function __construct(?StructureBranchGateway $gateway = null)
	{
		$this->gateway = $gateway ?? new StructureBranchGateway();
	}

	/**
	 * @param int[] $employeeIds
	 * @return int[]
	 */
	public function resolve(array $employeeIds): array
	{
		return self::flatten($this->resolveByEmployee($employeeIds));
	}

	/**
	 * @param int[] $employeeIds
	 * @return array<int, int[]>
	 */
	public function resolveByEmployee(array $employeeIds): array
	{
		$employeeIds = IdNormalizer::normalize($employeeIds);
		if (empty($employeeIds))
		{
			return [];
		}

		$unknownEmployeeIds = array_values(
			array_filter(
				$employeeIds,
				fn (int $employeeId) => !isset($this->memo[$employeeId]),
			),
		);
		if (!empty($unknownEmployeeIds))
		{
			$this->rememberBranchManagerIds($unknownEmployeeIds);
		}

		$managerIdsByEmployee = [];
		foreach ($employeeIds as $employeeId)
		{
			$managerIdsByEmployee[$employeeId] = $this->memo[$employeeId] ?? [];
		}

		return $managerIdsByEmployee;
	}

	/**
	 * @param int[] $employeeIds
	 */
	private function rememberBranchManagerIds(array $employeeIds): void
	{
		$nodeIdsByEmployee = $this->gateway->getDepartmentNodeIdsByUsers($employeeIds);
		$parentIdsByNode = $this->gateway->getAncestorNodeIds(self::flatten($nodeIdsByEmployee));

		$branchNodeIdsByEmployee = [];
		foreach ($employeeIds as $employeeId)
		{
			$nodeIds = $nodeIdsByEmployee[$employeeId] ?? [];

			$branchNodeIds = $nodeIds;
			foreach ($nodeIds as $nodeId)
			{
				array_push($branchNodeIds, ...($parentIdsByNode[$nodeId] ?? []));
			}

			$branchNodeIdsByEmployee[$employeeId] = IdNormalizer::normalize($branchNodeIds);
		}

		$managerIdsByNode = $this->gateway->getManagerIdsByNodes(self::flatten($branchNodeIdsByEmployee));

		foreach ($employeeIds as $employeeId)
		{
			$managerIds = [];
			foreach ($branchNodeIdsByEmployee[$employeeId] as $nodeId)
			{
				array_push($managerIds, ...($managerIdsByNode[$nodeId] ?? []));
			}

			$this->memo[$employeeId] = IdNormalizer::normalize($managerIds);
		}
	}

	/**
	 * @param array<int, int[]> $idsByKey
	 * @return int[]
	 */
	private static function flatten(array $idsByKey): array
	{
		return IdNormalizer::normalize(array_merge([], ...array_values($idsByKey)));
	}
}
