<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Integration\HumanResources;

use Bitrix\HumanResources\Enum\DepthLevel;
use Bitrix\HumanResources\Item\Collection\NodeCollection;
use Bitrix\HumanResources\Item\Node;
use Bitrix\HumanResources\Public\Service\Container as HrContainer;
use Bitrix\HumanResources\Public\Service\NodeMemberService;
use Bitrix\HumanResources\Public\Service\NodeService;
use Bitrix\HumanResources\Type\MemberEntityType;
use Bitrix\HumanResources\Type\NodeEntityType;
use Bitrix\HumanResources\Type\StructureAction;
use Bitrix\Main\Loader;
use Bitrix\Vibecodeconnector\Internal\Exception\OrgStructureUnavailableException;

class OrgStructureGateway
{
	private const OLD_ACCESS_CODE_PATTERN = '/^D[1-9][0-9]*$/D';
	private const MATCH_ACCESS_CODE_PATTERN = '/^(D|DR)([1-9][0-9]*)$/D';
	private const RECURSIVE_PREFIX = 'DR';
	private const SCAN_CHUNK_SIZE = 500;

	/**
	 * @return list<array{accessCode: string, name: string, parentAccessCode: string|null}>
	 * @throws OrgStructureUnavailableException
	 */
	public function listDepartments(int $viewerUserId, int $limit, int $offset): array
	{
		$structureId = $this->getDefaultStructureId();
		$nodes = $this->findNamedDepartments($structureId, $viewerUserId, $limit, $offset);
		$parentCodes = $this->resolveParentAccessCodes($nodes, $structureId, $viewerUserId);

		$result = [];
		foreach ($nodes as $node)
		{
			$result[] = [
				'accessCode' => (string)$node->accessCode,
				'name' => (string)$node->name,
				'parentAccessCode' => $parentCodes[(int)$node->parentId] ?? null,
			];
		}

		return $result;
	}

	/**
	 * @throws OrgStructureUnavailableException
	 */
	private function findNamedDepartments(int $structureId, int $viewerUserId, int $limit, int $offset): NodeCollection
	{
		$found = new NodeCollection();
		$seenIds = [];
		$skipped = 0;
		$scanOffset = 0;

		do
		{
			$chunk = $this->getNodeService()->findAll(
				structureId: $structureId,
				nodeTypes: [NodeEntityType::DEPARTMENT],
				structureAction: StructureAction::ViewAction,
				limit: self::SCAN_CHUNK_SIZE,
				offset: $scanOffset,
				viewerUserId: $viewerUserId,
			);

			foreach ($chunk as $node)
			{
				if (self::oldAccessCode($node) === null || isset($seenIds[(int)$node->id]))
				{
					continue;
				}

				$seenIds[(int)$node->id] = true;

				if ($skipped < $offset)
				{
					$skipped++;

					continue;
				}

				$found->add($node);
				if ($found->count() >= $limit)
				{
					return $found;
				}
			}

			$scanOffset += self::SCAN_CHUNK_SIZE;
		}
		while (!$chunk->empty());

		return $found;
	}

	/**
	 * D<n> matches direct membership in the department, DR<n> also membership in any of its subdepartments.
	 *
	 * @param list<string> $accessCodes
	 *
	 * @return array{matched: list<string>, unresolved: list<string>}
	 * @throws OrgStructureUnavailableException
	 */
	public function matchDepartments(int $userId, array $accessCodes): array
	{
		$structureId = $this->getDefaultStructureId();

		$plainCodeByAskedCode = [];
		$hasRecursiveCode = false;
		foreach ($accessCodes as $accessCode)
		{
			if (!is_string($accessCode) || array_key_exists($accessCode, $plainCodeByAskedCode))
			{
				continue;
			}

			$plainCodeByAskedCode[$accessCode] = null;
			if (preg_match(self::MATCH_ACCESS_CODE_PATTERN, $accessCode, $parts) === 1)
			{
				// Nodes are always asked by the plain code: older humanresources does not know DR codes.
				$plainCodeByAskedCode[$accessCode] = 'D' . $parts[2];
				$hasRecursiveCode = $hasRecursiveCode || $parts[1] === self::RECURSIVE_PREFIX;
			}
		}

		$resolvableCodes = array_values(array_unique(array_filter($plainCodeByAskedCode)));
		$nodeIdsByCode = $resolvableCodes === []
			? []
			: $this->getNodeService()->getNodeIdsGroupedByAccessCode($resolvableCodes)
		;

		$departmentIds = $this->filterDepartmentIds($nodeIdsByCode, $structureId);
		$memberNodeIds = [];
		$coveringNodeIds = [];
		if ($departmentIds !== [] && $hasRecursiveCode)
		{
			$recursiveNodeIds = [];
			foreach ($plainCodeByAskedCode as $accessCode => $plainCode)
			{
				if ($plainCode !== null && str_starts_with((string)$accessCode, self::RECURSIVE_PREFIX))
				{
					foreach ($nodeIdsByCode[$plainCode] ?? [] as $nodeId)
					{
						$recursiveNodeIds[(int)$nodeId] = true;
					}
				}
			}

			$memberNodeIds = $this->getMemberNodeIds($userId, null, $structureId);
			$coveringNodeIds = $this->findCoveringNodeIds(
				array_values(array_intersect(array_keys($recursiveNodeIds), $departmentIds)),
				$memberNodeIds,
				$structureId,
			);
		}
		elseif ($departmentIds !== [])
		{
			$memberNodeIds = $this->getMemberNodeIds($userId, $departmentIds, $structureId);
		}

		$matched = [];
		$unresolved = [];
		foreach ($plainCodeByAskedCode as $accessCode => $plainCode)
		{
			$accessCode = (string)$accessCode;
			$nodeIds = $plainCode === null
				? []
				: array_values(array_intersect($nodeIdsByCode[$plainCode] ?? [], $departmentIds))
			;
			if ($nodeIds === [])
			{
				$unresolved[] = $accessCode;

				continue;
			}

			$acceptedNodeIds = str_starts_with($accessCode, self::RECURSIVE_PREFIX) ? $coveringNodeIds : $memberNodeIds;
			foreach ($nodeIds as $nodeId)
			{
				if (isset($acceptedNodeIds[$nodeId]))
				{
					$matched[] = $accessCode;

					break;
				}
			}
		}

		return ['matched' => $matched, 'unresolved' => $unresolved];
	}

	/**
	 * @param list<int> $recursiveNodeIds
	 * @param array<int, true> $memberNodeIds
	 *
	 * @return array<int, true> departments containing the user within the requested subtrees
	 * @throws OrgStructureUnavailableException
	 */
	private function findCoveringNodeIds(array $recursiveNodeIds, array $memberNodeIds, int $structureId): array
	{
		$nodes = $this->getNodeService()->findChildrenByNodeIds(
			nodeIds: $recursiveNodeIds,
			structureId: $structureId,
			nodeTypes: [NodeEntityType::DEPARTMENT],
			depthLevel: DepthLevel::FULL,
		);
		$parentIds = [];
		foreach ($nodes as $node)
		{
			$parentIds[(int)$node->id] = (int)$node->parentId;
		}

		$result = [];
		foreach (array_keys($memberNodeIds) as $nodeId)
		{
			while (isset($parentIds[$nodeId]) && !isset($result[$nodeId]))
			{
				$result[$nodeId] = true;
				$nodeId = $parentIds[$nodeId];
			}
		}

		return $result;
	}

	/**
	 * @param array<string, list<int>> $nodeIdsByCode
	 *
	 * @return list<int>
	 */
	private function filterDepartmentIds(array $nodeIdsByCode, int $structureId): array
	{
		$nodeIds = [];
		foreach ($nodeIdsByCode as $codeNodeIds)
		{
			foreach ($codeNodeIds as $nodeId)
			{
				$nodeIds[(int)$nodeId] = true;
			}
		}

		if ($nodeIds === [])
		{
			return [];
		}

		$departments = $this->getNodeService()->findAllByIds(
			nodeIds: array_keys($nodeIds),
			structureId: $structureId,
			nodeTypes: [NodeEntityType::DEPARTMENT],
		);

		return $departments->getIds();
	}

	/**
	 * @param list<int>|null $departmentIds null asks about every department of the user
	 *
	 * @return array<int, true>
	 * @throws OrgStructureUnavailableException
	 */
	private function getMemberNodeIds(int $userId, ?array $departmentIds, int $structureId): array
	{
		$members = $this->getNodeMemberService()->findAllByEntityIds(
			entityIds: [$userId],
			memberEntityType: MemberEntityType::USER,
			nodeIds: $departmentIds,
			nodeTypes: [NodeEntityType::DEPARTMENT],
			structureId: $structureId,
		);

		return array_fill_keys($members->getNodeIds(), true);
	}

	public static function isOldAccessCode(mixed $accessCode): bool
	{
		return is_string($accessCode) && preg_match(self::OLD_ACCESS_CODE_PATTERN, $accessCode) === 1;
	}

	/**
	 * @return array<int, string>
	 */
	private function resolveParentAccessCodes(NodeCollection $nodes, int $structureId, int $viewerUserId): array
	{
		$codes = [];
		$missing = [];
		foreach ($nodes as $node)
		{
			$codes[(int)$node->id] = self::oldAccessCode($node);
		}

		foreach ($nodes as $node)
		{
			$parentId = (int)$node->parentId;
			if ($parentId > 0 && !array_key_exists($parentId, $codes))
			{
				$missing[$parentId] = true;
			}
		}

		if ($missing !== [])
		{
			$parents = $this->getNodeService()->findAllByIds(
				nodeIds: array_keys($missing),
				structureId: $structureId,
				nodeTypes: [NodeEntityType::DEPARTMENT],
				structureAction: StructureAction::ViewAction,
				viewerUserId: $viewerUserId,
			);
			foreach ($parents as $parent)
			{
				$codes[(int)$parent->id] = self::oldAccessCode($parent);
			}
		}

		return array_filter($codes, static fn (?string $code): bool => $code !== null);
	}

	private static function oldAccessCode(Node $node): ?string
	{
		return self::isOldAccessCode($node->accessCode) ? $node->accessCode : null;
	}

	/**
	 * @throws OrgStructureUnavailableException
	 */
	protected function getDefaultStructureId(): int
	{
		$root = $this->getNodeService()->getRootNode();
		$structureId = (int)($root?->structureId ?? 0);
		if ($structureId <= 0)
		{
			throw OrgStructureUnavailableException::notInitialized();
		}

		return $structureId;
	}

	/**
	 * @throws OrgStructureUnavailableException
	 */
	protected function getNodeService(): NodeService
	{
		$this->requireModule();

		return HrContainer::getNodeService() ?? throw OrgStructureUnavailableException::moduleUnavailable();
	}

	/**
	 * @throws OrgStructureUnavailableException
	 */
	protected function getNodeMemberService(): NodeMemberService
	{
		$this->requireModule();

		return HrContainer::getNodeMemberService() ?? throw OrgStructureUnavailableException::moduleUnavailable();
	}

	/**
	 * @throws OrgStructureUnavailableException
	 */
	private function requireModule(): void
	{
		if (!Loader::includeModule('humanresources'))
		{
			throw OrgStructureUnavailableException::moduleUnavailable();
		}
	}
}
