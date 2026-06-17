<?php

declare(strict_types=1);

namespace Bitrix\Timeman\Integration\Humanresources;

use Bitrix\HumanResources\Type\NodeSettingsAuthorityType;

final class ReportsAuthorityLogic
{
	/**
	 * @param array<int> $nodeIds
	 * @param array<int, mixed> $reportsAuthorityByNode
	 * @param list<NodeSettingsAuthorityType> $allowedTypes
	 * @return list<int>
	 */
	public function filterNodeIdsByAllowedTypes(
		array $nodeIds,
		array $reportsAuthorityByNode,
		array $allowedTypes,
	): array {
		if (empty($nodeIds))
		{
			return [];
		}

		$filtered = array_filter($nodeIds, function ($nodeId) use ($reportsAuthorityByNode, $allowedTypes) {
			if (!isset($reportsAuthorityByNode[$nodeId]))
			{
				return false;
			}

			$authority = $reportsAuthorityByNode[$nodeId];
			foreach ($allowedTypes as $type)
			{
				if ($this->authorityHas($authority, $type))
				{
					return true;
				}
			}

			return false;
		});

		return array_values($filtered);
	}

	/**
	 * @param array<int> $nodeIds
	 * @param array<int, mixed> $reportsAuthorityByNode
	 * @return array{0: list<int>, 1: list<int>}
	 */
	public function splitNodeIdsByAllowedTypes(
		array $nodeIds,
		array $reportsAuthorityByNode,
		NodeSettingsAuthorityType $headType,
		NodeSettingsAuthorityType $deputyType,
	): array {
		if (empty($nodeIds))
		{
			return [[], []];
		}

		$headNodeIds = [];
		$deputyNodeIds = [];
		foreach ($nodeIds as $nodeId)
		{
			if (!isset($reportsAuthorityByNode[$nodeId]))
			{
				continue;
			}

			$authority = $reportsAuthorityByNode[$nodeId];
			if ($this->authorityHas($authority, $headType))
			{
				$headNodeIds[] = $nodeId;
			}
			if ($this->authorityHas($authority, $deputyType))
			{
				$deputyNodeIds[] = $nodeId;
			}
		}

		return [$headNodeIds, $deputyNodeIds];
	}

	/**
	 * @param array<int, list<int>> $teamReportExceptionsByNode
	 */
	public function isUserExcludedFromTeamReports(int $userId, array $teamReportExceptionsByNode): bool
	{
		if ($userId <= 0 || empty($teamReportExceptionsByNode))
		{
			return false;
		}

		foreach ($teamReportExceptionsByNode as $exceptionUserIds)
		{
			if (!is_array($exceptionUserIds))
			{
				continue;
			}

			foreach ($exceptionUserIds as $exceptionUserId)
			{
				if ((int)$exceptionUserId === $userId)
				{
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * @param mixed $authority
	 */
	private function authorityHas($authority, NodeSettingsAuthorityType $type): bool
	{
		if (is_array($authority))
		{
			return in_array($type, $authority, true);
		}

		if (is_object($authority) && method_exists($authority, 'has'))
		{
			return $authority->has($type);
		}

		return false;
	}
}
