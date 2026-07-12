<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Integration\HumanResources\Service;

use Bitrix\HumanResources\Item\NodeMember;
use Bitrix\HumanResources\Item\NodeRelation;
use Bitrix\HumanResources\Service\Container;
use Bitrix\HumanResources\Type\RelationEntityType;
use Bitrix\Main\Loader;
use Bitrix\Socialnetwork\V2\Internal;
use Bitrix\Socialnetwork\V2\Internal\Entity\Project\Member\MemberEntity;
use Bitrix\Socialnetwork\V2\Internal\Entity\Project\Member\MemberEntityCollection;
use Bitrix\Socialnetwork\V2\Internal\Entity\Project\Member\MemberEntityType;
use Bitrix\Socialnetwork\V2\Internal\Integration\HumanResources\Mapper\DepartmentRelationMapper;
use Bitrix\Socialnetwork\V2\Internal\Repository\Mapper\MemberEntityMapper;
use Bitrix\Socialnetwork\V2\Internal\Repository\WorkgroupRepository;
use Bitrix\Socialnetwork\V2\Internal\Service\StructureSync\CollabRelationInfo;

class StructureRelationService
{
	/** @var array<int, CollabRelationInfo[]> */
	private array $collabRelationsCache = [];

	/** @var array<int, int[]> */
	private array $chatRelationsCache = [];

	public function __construct(
		private readonly MemberEntityMapper $memberEntityMapper,
		private readonly DepartmentRelationMapper $departmentRelationMapper,
		private readonly WorkgroupRepository $workgroupRepository,
	)
	{
	}

	public function getEmployeeIds(
		int $nodeId,
		bool $withChildNodes,
		int $offset = 0,
		int $limit = 500,
	): array
	{
		if (!Loader::includeModule('humanresources'))
		{
			return [];
		}

		$employees = Container::getNodeMemberService()
			->getPagedEmployees($nodeId, $withChildNodes, $offset, $limit)
			->getValues()
		;

		return array_map(
			static fn(NodeMember $employee): int => $employee->entityId,
			$employees,
		);
	}

	/**
	 * @return CollabRelationInfo[]
	 */
	public function getCollabRelations(int $nodeId): array
	{
		if (isset($this->collabRelationsCache[$nodeId]))
		{
			return $this->collabRelationsCache[$nodeId];
		}

		if (!Loader::includeModule('humanresources'))
		{
			return [];
		}

		$relations = Container::getNodeRelationRepository()
			->findRelationsByNodeIdAndRelationType($nodeId, RelationEntityType::COLLAB)
		;

		$this->collabRelationsCache[$nodeId] = array_map(
			static fn(NodeRelation $relation) => new CollabRelationInfo(
				$relation->entityId,
				$relation->createdBy,
			),
			$relations->getValues(),
		);

		return $this->collabRelationsCache[$nodeId];
	}

	/**
	 * @param string[] $accessCodes Department access codes (D123, DR456)
	 */
	public function linkDepartments(array $accessCodes, int $collabId): void
	{
		if (!Loader::includeModule('humanresources'))
		{
			return;
		}

		// HR batch API (linkNodeRelationCollection) requires pre-resolved nodeIds and withChildNodes,
		// but there is no batch accessCode→nodeId resolver. With N=1–3 codes from UI EntitySelector,
		// the per-code method is simpler and avoids duplicating HR's internal isRecursive() logic.
		$nodeRelationService = Container::getNodeRelationService();
		foreach ($accessCodes as $accessCode)
		{
			$nodeRelationService->linkEntityToNodeByAccessCode(
				$accessCode,
				RelationEntityType::COLLAB,
				$collabId,
			);
		}
	}

	/**
	 * @param string[] $accessCodes Department access codes (D123, DR456)
	 */
	public function unlinkDepartments(array $accessCodes, int $collabId, int $initiatorId = 0): void
	{
		if (empty($accessCodes))
		{
			return;
		}

		$this->unlinkNodeRelationDepartments($accessCodes, $collabId);
		$this->unlinkLegacyDepartments($accessCodes, $collabId, $initiatorId);
	}

	private function unlinkNodeRelationDepartments(array $accessCodes, int $collabId): void
	{
		if (!Loader::includeModule('humanresources'))
		{
			return;
		}

		// \Bitrix\HumanResources\Service\NodeRelationService::unlinkEntityFromNodeByAccessCode
		// throws TypeError when a relation is absent.
		// Keep only codes that are actually linked.
		$codesToUnlink = array_intersect($accessCodes, $this->getNodeRelationDepartmentAccessCodes($collabId));
		if (empty($codesToUnlink))
		{
			return;
		}

		// Same reasoning as linkDepartments: no batch accessCode→nodeId resolver in HR.
		$nodeRelationService = Container::getNodeRelationService();
		foreach ($codesToUnlink as $accessCode)
		{
			$nodeRelationService->unlinkEntityFromNodeByAccessCode(
				$accessCode,
				RelationEntityType::COLLAB,
				$collabId,
			);
		}
	}

	private function unlinkLegacyDepartments(array $accessCodes, int $collabId, int $initiatorId): void
	{
		$departmentIdsToUnlink =
			$this->memberEntityMapper
				->fromAccessCodes($accessCodes)
				->filterByType(MemberEntityType::Department)
				->getIds()
		;

		if (empty($departmentIdsToUnlink))
		{
			return;
		}

		$departmentIdsToUnlinkMap = array_fill_keys($departmentIdsToUnlink, true);
		foreach ($this->getLegacyDepartments($collabId) as $department)
		{
			if (!isset($departmentIdsToUnlinkMap[$department->getId()]))
			{
				continue;
			}

			// StructureSyncService depends on this service, so it cannot be constructor-injected here without a cycle
			Internal\DI\Container::getInstance()
				->getStructureSyncService()
				->enqueueLegacyDepartmentDeleted($collabId, $department->getId(), $initiatorId)
			;
		}
	}

	/**
	 * @return string[] Access codes (D123, DR456) of departments linked to collab
	 */
	public function getLinkedDepartmentAccessCodes(int $collabId): array
	{
		return $this->memberEntityMapper->toAccessCodes($this->getLinkedDepartments($collabId));
	}

	public function getLinkedDepartments(int $collabId): MemberEntityCollection
	{
		$departmentsById = [];
		foreach ($this->getLegacyDepartments($collabId) as $department)
		{
			$departmentsById[$department->getId()] = $department;
		}

		foreach ($this->getNodeRelationDepartments($collabId) as $department)
		{
			$departmentsById[$department->getId()] = $department;
		}

		return new MemberEntityCollection(...array_values($departmentsById));
	}

	private function getNodeRelationDepartmentAccessCodes(int $collabId): array
	{
		return $this->memberEntityMapper->toAccessCodes($this->getNodeRelationDepartments($collabId));
	}

	/**
	 * HR-only departments (excludes legacy UF_SG_DEPT)
	 */
	private function getNodeRelationDepartments(int $collabId): MemberEntityCollection
	{
		$departments = new MemberEntityCollection();

		foreach ($this->findDepartmentRelations($collabId) as $relation)
		{
			$department = $this->departmentRelationMapper->map($relation);
			if ($department !== null)
			{
				$departments->add($department);
			}
		}

		return $departments;
	}

	/**
	 * Departments attached to a project the legacy way (the UF_SG_DEPT field) have no HR node relation
	 */
	private function getLegacyDepartments(int $collabId): MemberEntityCollection
	{
		$departments = new MemberEntityCollection();

		foreach ($this->workgroupRepository->getLegacyDepartmentIds($collabId) as $departmentId)
		{
			$departments->add(new MemberEntity(
				id: $departmentId,
				type: MemberEntityType::Department,
				// Legacy UF_SG_DEPT membership always includes sub-departments
				withChildNodes: true,
			));
		}

		return $departments;
	}

	/**
	 * @return int[] chat ids linked to the given structure node
	 */
	public function getChatRelations(int $nodeId): array
	{
		if (isset($this->chatRelationsCache[$nodeId]))
		{
			return $this->chatRelationsCache[$nodeId];
		}

		if (!Loader::includeModule('humanresources'))
		{
			return [];
		}

		$relations = Container::getNodeRelationRepository()
			->findRelationsByNodeIdAndRelationType($nodeId, RelationEntityType::CHAT)
		;

		$this->chatRelationsCache[$nodeId] = array_map(
			static fn(NodeRelation $relation): int => $relation->entityId,
			$relations->getValues(),
		);

		return $this->chatRelationsCache[$nodeId];
	}

	/**
	 * @param int[] $chatIds
	 */
	public function unlinkChatRelations(int $nodeId, array $chatIds): void
	{
		if (empty($chatIds) || !Loader::includeModule('humanresources'))
		{
			return;
		}

		Container::getNodeRelationService()
			->unlinkByEntityIdsAndNodeIdAndType($nodeId, $chatIds, RelationEntityType::CHAT)
		;

		unset($this->chatRelationsCache[$nodeId]);
	}

	public function getUsersNotInOtherRelations(int $projectId, array $userIds): array
	{
		if (!Loader::includeModule('humanresources'))
		{
			return [];
		}

		return Container::getNodeRelationService()
			->getUsersNotInRelation(RelationEntityType::COLLAB, $projectId, $userIds)
		;
	}

	private function findDepartmentRelations(int $collabId): iterable
	{
		if (!Loader::includeModule('humanresources'))
		{
			return [];
		}

		return Container::getNodeRelationService()
			->findAllRelationsByEntityTypeAndEntityId(RelationEntityType::COLLAB, $collabId)
		;
	}
}
