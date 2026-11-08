<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Integration\HumanResources;

use Bitrix\HumanResources\Enum\DepthLevel;
use Bitrix\HumanResources\Public\Service\Container;
use Bitrix\HumanResources\Type\NodeEntityType;
use Bitrix\Main\DB\ConnectionException;
use Bitrix\Main\DB\SqlException;
use Bitrix\Main\Loader;
use Bitrix\Vibecodeconnector\Internal\Exception\LiveDepartmentAccessUnavailableException;
use Bitrix\Main\UserTable;
use Bitrix\Vibecodeconnector\Internal\Integration\Main\AudienceCodeSet;

class StructureGate
{
	private const USER_CHUNK_SIZE = 2000;

	/** @var array<string, int[]> */
	private array $resolvedNodeIds = [];

	/** @var array<int, int[]> */
	private array $nodeWithAncestors = [];

	public function isAvailable(): bool
	{
		return Loader::includeModule('humanresources');
	}

	/**
	 * @param int[] $userIds
	 *
	 * @return int[]
	 */
	public function filterMembers(array $userIds, AudienceCodeSet $codes): array
	{
		$userIds = array_values(array_filter(
			array_unique(array_map('intval', $userIds)),
			static fn (int $userId): bool => $userId > 0,
		));
		if ($userIds === [] || $codes->namesNoStructure() || !$this->isAvailable())
		{
			return [];
		}

		$nodeIds = $this->resolveNodeIds($codes);
		if ($nodeIds === [])
		{
			return [];
		}

		$members = [];
		foreach (array_chunk($userIds, self::USER_CHUNK_SIZE) as $chunk)
		{
			$members += array_fill_keys(
				Container::getNodeMemberService()->findAllByEntityIds(
					entityIds: $chunk,
					nodeIds: $nodeIds,
					withVirtualUsers: false,
				)->getUniqueEntityIds(),
				true,
			);
		}

		return array_values(array_filter(
			$userIds,
			static fn (int $userId): bool => isset($members[$userId]),
		));
	}

	public function filterDepartmentMembers(array $userIds, array $codes): array
	{
		$userIds = array_values(array_filter(
			array_unique(array_map('intval', $userIds)),
			static fn (int $userId): bool => $userId > 0,
		));
		if ($userIds === [] || $codes === [])
		{
			return [];
		}

		$nodeIds = [];
		$recursiveRoots = [];
		foreach ($this->mapDepartmentCodesToNodes($codes) as $code => $mappedNodes)
		{
			foreach ($mappedNodes as $nodeId)
			{
				if ($nodeId <= 0)
				{
					continue;
				}
				$nodeIds[$nodeId] = true;
				if (str_starts_with($code, 'DR'))
				{
					$recursiveRoots[$nodeId] = true;
				}
			}
		}
		if ($recursiveRoots !== [])
		{
			$children = $this->callLiveHr(fn () => $this->nodeService()->findChildrenByNodeIds(
				nodeIds: array_keys($recursiveRoots),
				nodeTypes: [NodeEntityType::DEPARTMENT],
				depthLevel: DepthLevel::FULL,
			))->getIds();
			$nodeIds += array_fill_keys($children, true);
		}
		$nodeIds = array_keys($nodeIds);
		if ($nodeIds === [])
		{
			return [];
		}

		$members = [];
		foreach (array_chunk($userIds, self::USER_CHUNK_SIZE) as $chunk)
		{
			$members += array_fill_keys($this->callLiveHr(fn () => $this->nodeMemberService()->findAllByEntityIds(
				entityIds: $chunk,
				nodeIds: $nodeIds,
				withVirtualUsers: false,
			))->getUniqueEntityIds(), true);
		}

		return array_values(array_filter(
			$userIds,
			static fn (int $userId): bool => isset($members[$userId]),
		));
	}

	/**
	 * @param string[] $codes
	 *
	 * @return array<string, int[]>
	 */
	public function mapDepartmentCodesToNodes(array $codes): array
	{
		$set = AudienceCodeSet::fromCodes($codes);
		$recognized = array_merge(
			$set->departmentCodes,
			array_map(static fn (string $code): string => 'DR' . substr($code, 1), $set->recursiveDepartmentCodes),
		);
		$departmentCodes = array_values(array_intersect(array_unique($codes), $recognized));
		if ($departmentCodes === [] || !$this->isAvailable())
		{
			return [];
		}

		$grouped = $this->callLiveHr(fn () => $this->nodeService()->getNodeIdsGroupedByAccessCode($departmentCodes));

		$map = [];
		foreach ($departmentCodes as $code)
		{
			$map[$code] = array_values(array_unique(array_map('intval', $grouped[$code] ?? [])));
		}

		return $map;
	}

	/**
	 * @param array<string, int[]> $nodeIdsByCode
	 *
	 * @return string[]
	 */
	public function confirmDepartmentCodes(int $userId, array $nodeIdsByCode): array
	{
		$this->nodeWithAncestors = [];
		$nodeIdsByCode = array_filter($nodeIdsByCode, static fn (array $nodeIds): bool => $nodeIds !== []);
		if ($userId <= 0 || $nodeIdsByCode === [] || !$this->isAvailable())
		{
			return [];
		}

		$userNodeIds = array_values(array_unique(
			$this->callLiveHr(fn () => $this->nodeMemberService()->findAllByEntityIds(
				entityIds: [$userId],
				withVirtualUsers: false,
			))->getNodeIds(),
		));
		if ($userNodeIds === [])
		{
			return [];
		}

		$userNodes = array_fill_keys($userNodeIds, true);
		$userNodesWithAncestors = null;
		$confirmed = [];
		foreach ($nodeIdsByCode as $code => $nodeIds)
		{
			$code = (string)$code;
			if (str_starts_with($code, 'DR'))
			{
				$userNodesWithAncestors ??= $this->withAncestors($userNodeIds);
				$scope = $userNodesWithAncestors;
			}
			else
			{
				$scope = $userNodes;
			}

			foreach ($nodeIds as $nodeId)
			{
				if (isset($scope[(int)$nodeId]))
				{
					$confirmed[] = $code;

					break;
				}
			}
		}

		return $confirmed;
	}

	/**
	 * @return int[]
	 */
	public function enumerateMembers(AudienceCodeSet $codes, int $limit, int $afterUserId = 0): array
	{
		if ($limit <= 0 || $codes->namesNoStructure() || !$this->isAvailable())
		{
			return [];
		}

		$nodeIds = $this->resolveNodeIds($codes);
		if ($nodeIds === [])
		{
			return [];
		}

		return $this->fetchMemberIdsKeysetPage($nodeIds, $limit, max(0, $afterUserId));
	}

	/**
	 * @param int[] $nodeIds
	 *
	 * @return int[]
	 */
	private function fetchMemberIdsKeysetPage(array $nodeIds, int $limit, int $afterUserId): array
	{
		$query = UserTable::query()
			->setSelect(['ID'])
			->setDistinct()
			->where('ID', '>', $afterUserId)
			->where('REAL_USER', 'expr', true)
			->setOrder(['ID' => 'ASC'])
			->setLimit($limit)
		;
		$rows = Container::getNodeMemberService()
			->injectUserNodeSubquery($query, nodeIds: $nodeIds)
			->fetchAll()
		;

		$userIds = array_values(array_unique(array_map(
			static fn (array $row): int => (int)$row['ID'],
			$rows,
		)));
		sort($userIds, SORT_NUMERIC);

		return $userIds;
	}

	/**
	 * @return int[]
	 */
	private function resolveNodeIds(AudienceCodeSet $codes): array
	{
		$cacheKey = $this->nodeCacheKey($codes);
		if (array_key_exists($cacheKey, $this->resolvedNodeIds))
		{
			return $this->resolvedNodeIds[$cacheKey];
		}

		$nodeService = Container::getNodeService();

		$named = $nodeService->getNodeIdsByAccessCodes($codes->departmentCodes);
		$recursive = $nodeService->getNodeIdsByAccessCodes($codes->recursiveDepartmentCodes);

		$descendants = $recursive === [] ? [] : $nodeService->findChildrenByNodeIds(
			nodeIds: array_values(array_unique($recursive)),
			depthLevel: DepthLevel::FULL,
		)->getIds();

		$candidates = array_values(array_unique(array_merge($named, $descendants)));

		return $this->resolvedNodeIds[$cacheKey] = $candidates === []
			? []
			: $nodeService->findAllByIds($candidates)->getIds();
	}

	/**
	 * @param int[] $nodeIds
	 *
	 * @return array<int, true>
	 */
	private function withAncestors(array $nodeIds): array
	{
		$scope = [];
		foreach ($nodeIds as $nodeId)
		{
			$this->nodeWithAncestors[$nodeId] ??= array_merge(
				[$nodeId],
				$this->callLiveHr(fn () => $this->nodeService()->findParentsByNodeId(
					$nodeId,
					[NodeEntityType::DEPARTMENT],
					DepthLevel::FULL,
				))?->getIds() ?? [],
			);
			$scope += array_fill_keys($this->nodeWithAncestors[$nodeId], true);
		}

		return $scope;
	}

	private function callLiveHr(callable $operation): mixed
	{
		try
		{
			return $operation();
		}
		catch (SqlException|ConnectionException $exception)
		{
			throw new LiveDepartmentAccessUnavailableException('Live department access unavailable', 0, $exception);
		}
	}

	protected function nodeService(): \Bitrix\HumanResources\Public\Service\NodeService
	{
		return Container::getNodeService();
	}

	protected function nodeMemberService(): \Bitrix\HumanResources\Public\Service\NodeMemberService
	{
		return Container::getNodeMemberService();
	}

	private function nodeCacheKey(AudienceCodeSet $codes): string
	{
		$departmentCodes = $codes->departmentCodes;
		$recursiveDepartmentCodes = $codes->recursiveDepartmentCodes;
		sort($departmentCodes);
		sort($recursiveDepartmentCodes);

		return implode(',', $departmentCodes) . '|' . implode(',', $recursiveDepartmentCodes);
	}
}
