<?php

namespace Bitrix\HumanResources\Public\Service;

use Bitrix\HumanResources\Enum\DepthLevel;
use Bitrix\HumanResources\Internals\Service\Container as InternalContainer;
use Bitrix\HumanResources\Repository\NodeSettingsRepository;
use Bitrix\HumanResources\Service\Container;
use Bitrix\HumanResources\Type\MemberEntityType;
use Bitrix\HumanResources\Type\NodeEntityType;
use Bitrix\HumanResources\Type\NodeMemberRole;
use Bitrix\HumanResources\Type\NodeSettingsAuthorityType;
use Bitrix\HumanResources\Type\NodeSettingsAuthorityTypeCollection;
use Bitrix\HumanResources\Type\NodeSettingsType;

class NodeSettingsService
{
	private NodeSettingsRepository $nodeSettingsRepository;

	public function __construct(?NodeSettingsRepository $nodeSettingsRepository = null)
	{
		$this->nodeSettingsRepository = $nodeSettingsRepository ?? Container::getNodeSettingsRepository();
	}

	/**
	 * Get NodeSettingsType::BusinessProcTeamRule settings values
	 *
	 * @param int $nodeId
	 * @return NodeSettingsAuthorityTypeCollection
	 * @throws \Bitrix\HumanResources\Exception\WrongStructureItemException
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	public function getBusinessProcAuthoritySettings(int $nodeId): NodeSettingsAuthorityTypeCollection
	{
		$entityCollection = $this->nodeSettingsRepository->getByNodesAndTypes($nodeId, [NodeSettingsType::BusinessProcAuthority]);

		$authorityCollection = new NodeSettingsAuthorityTypeCollection();
		foreach ($entityCollection as $entity)
		{
			$authorityCollection->add(NodeSettingsAuthorityType::tryFrom($entity->settingsValue));
		}

		return $authorityCollection;
	}

	/**
	 * Same as getBusinessProcAuthoritySettings but for node array
	 *
	 * @param array $nodeIds
	 * @return array<NodeSettingsAuthorityTypeCollection> - associative array where key is nodeId and value is NodeSettingsAuthorityTypeCollection
	 * @throws \Bitrix\HumanResources\Exception\WrongStructureItemException
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	public function getBusinessProcAuthoritySettingsForNodes(array $nodeIds): array
	{
		$entityCollection = $this->nodeSettingsRepository->getByNodesAndTypes($nodeIds, [NodeSettingsType::BusinessProcAuthority]);

		$result = [];
		foreach ($entityCollection as $entity)
		{
			// Collect settingsValue per node_id
			$nodeId = $entity->nodeId;
			$result[$nodeId] ??= new NodeSettingsAuthorityTypeCollection();
			$result[$nodeId]->add(NodeSettingsAuthorityType::tryFrom($entity->settingsValue));
		}

		return $result;
	}

	/**
	 * Get NodeSettingsType::ReportsAuthority settings values
	 * that should represent who can get reports from users of the given node
	 *
	 * @param int $nodeId
	 * @return NodeSettingsAuthorityTypeCollection
	 * @throws \Bitrix\HumanResources\Exception\WrongStructureItemException
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	public function getReportsAuthoritySettings(int $nodeId): NodeSettingsAuthorityTypeCollection
	{
		$entityCollection = $this->nodeSettingsRepository->getByNodesAndTypes($nodeId, [NodeSettingsType::ReportsAuthority]);

		// workaround for teams and departments which was created before the new settings
		if ($entityCollection->count() === 0)
		{
			return new NodeSettingsAuthorityTypeCollection(NodeSettingsAuthorityType::DepartmentHead);
		}

		$authorityCollection = new NodeSettingsAuthorityTypeCollection();
		foreach ($entityCollection as $entity)
		{
			$authorityCollection->add(NodeSettingsAuthorityType::tryFrom($entity->settingsValue));
		}

		return $authorityCollection;
	}

	/**
	 * Same as getReportsAuthoritySettings but for node array
	 *
	 * @param array $nodeIds
	 * @return array<NodeSettingsAuthorityTypeCollection> - associative array where key is nodeId and value is NodeSettingsAuthorityTypeCollection
	 * @throws \Bitrix\HumanResources\Exception\WrongStructureItemException
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	public function getReportsAuthoritySettingsForNodes(array $nodeIds): array
	{
		$entityCollection = $this->nodeSettingsRepository->getByNodesAndTypes($nodeIds, [NodeSettingsType::ReportsAuthority]);

		$result = [];
		// Collect settings per node
		foreach ($entityCollection as $entity)
		{
			$nodeId = $entity->nodeId;
			$result[$nodeId] ??= new NodeSettingsAuthorityTypeCollection();
			$result[$nodeId]->add(NodeSettingsAuthorityType::tryFrom($entity->settingsValue));
		}

		// Apply default for nodes without values
		foreach ($nodeIds as $nodeId)
		{
			if (!array_key_exists($nodeId, $result) || empty($result[$nodeId]))
			{
				$result[$nodeId] = new NodeSettingsAuthorityTypeCollection(NodeSettingsAuthorityType::DepartmentHead);
			}
		}

		return $result;
	}

	/**
	 * @param array $nodeIds
	 * @return array
	 * @throws \Bitrix\HumanResources\Exception\WrongStructureItemException
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	public function getTeamReportExceptionsSettingsForNodes(array $nodeIds): array
	{
		$entityCollection = $this->nodeSettingsRepository->getByNodesAndTypes($nodeIds, [NodeSettingsType::TeamReportExceptions]);

		$result = [];
		foreach ($entityCollection as $entity)
		{
			$result[$entity->nodeId] ??= [];
			$result[$entity->nodeId][] = (int)$entity->settingsValue;
		}

		return $result;
	}

	/**
	 * Get the auto check-in flag for a node.
	 *
	 * @return ?bool null when the setting has never been saved for the node
	 */
	public function getAutoCheckinSetting(int $nodeId): ?bool
	{
		$entity = $this->nodeSettingsRepository
			->getByNodesAndTypes($nodeId, [NodeSettingsType::AutoCheckin])
			->getValues()[0] ?? null
		;

		return NodeSettingsType::booleanFromString($entity?->settingsValue);
	}

	/**
	 * Get the auto check-in flag for several nodes.
	 *
	 * @param int[] $nodeIds
	 * @return array<int, ?bool> nodeId => flag; null when no value is stored
	 */
	public function getAutoCheckinSettingsForNodes(array $nodeIds): array
	{
		$entityCollection = $this->nodeSettingsRepository
			->getByNodesAndTypes($nodeIds, [NodeSettingsType::AutoCheckin])
		;

		$result = array_fill_keys($nodeIds, null);
		foreach ($entityCollection as $entity)
		{
			$result[$entity->nodeId] = NodeSettingsType::booleanFromString($entity->settingsValue);
		}

		return $result;
	}

	/**
	 * Upsert the auto check-in flag for a node. This is the only supported write path
	 * for this setting: {@see SaveNodeSettingsCommand} rejects AutoCheckin by design.
	 */
	public function setAutoCheckinSetting(int $nodeId, bool $value): void
	{
		InternalContainer::getNodeSettingsService()
			->setBooleanSetting($nodeId, NodeSettingsType::AutoCheckin, $value)
		;
	}

	/**
	 * Returns WelcomeBox settings for all departments where the user is a direct member,
	 * grouped by the user's role in those departments.
	 *
	 * Keys are {@see NodeMemberRole} values (MEMBER_HEAD, MEMBER_DEPUTY_HEAD, MEMBER_EMPLOYEE).
	 * Values are associative arrays: nodeId => ?bool (null when setting has never been saved).
	 * Only non-empty role buckets are included in the result.
	 *
	 * @return array<string, array<int, ?bool>>
	 */
	public function getWelcomeBoxSettingsForMyDepartments(int $userId): array
	{
		return $this->loadBooleanSettingsForMyDepartments($userId, NodeSettingsType::WelcomeBox);
	}

	/**
	 * Returns WelcomeBox setting for departments where the user is head or deputy head (no subtree).
	 *
	 * @return array<int, ?bool> nodeId => flag; null when no value is stored
	 */
	public function getWelcomeBoxSettingsForManagedDepartments(int $userId, ?int $structureId = null): array
	{
		return $this->loadBooleanSettingsForManagedDepartments($userId, NodeSettingsType::WelcomeBox, $structureId);
	}

	/**
	 * Returns AutoCheckin settings for all departments where the user is a direct member,
	 * grouped by the user's role.
	 *
	 * @see getWelcomeBoxSettingsForMyDepartments for the result shape.
	 *
	 * @return array<string, array<int, ?bool>>
	 */
	public function getAutoCheckinSettingsForMyDepartments(int $userId): array
	{
		return $this->loadBooleanSettingsForMyDepartments($userId, NodeSettingsType::AutoCheckin);
	}

	/**
	 * Returns AutoCheckin setting for departments where the user is head or deputy head (no subtree).
	 *
	 * @return array<int, ?bool> nodeId => flag; null when no value is stored
	 */
	public function getAutoCheckinSettingsForManagedDepartments(int $userId, ?int $structureId = null): array
	{
		return $this->loadBooleanSettingsForManagedDepartments($userId, NodeSettingsType::AutoCheckin, $structureId);
	}

	/**
	 * Returns AiReports settings for all departments where the user is a direct member,
	 * grouped by the user's role.
	 *
	 * @see getWelcomeBoxSettingsForMyDepartments for the result shape.
	 *
	 * @return array<string, array<int, ?bool>>
	 */
	public function getAiReportsSettingsForMyDepartments(int $userId): array
	{
		return $this->loadBooleanSettingsForMyDepartments($userId, NodeSettingsType::AiReports);
	}

	/**
	 * Returns DayStartCheckinRequired settings for all departments where the user is a direct member,
	 * grouped by the user's role.
	 *
	 * @see getWelcomeBoxSettingsForMyDepartments for the result shape.
	 *
	 * @return array<string, array<int, ?bool>>
	 */
	public function getDayStartCheckinRequiredSettingsForMyDepartments(int $userId): array
	{
		return $this->loadBooleanSettingsForMyDepartments($userId, NodeSettingsType::DayStartCheckinRequired);
	}

	/**
	 * Returns DayStartCheckinRequired setting for departments where the user is head or deputy head (no subtree).
	 *
	 * @return array<int, ?bool> nodeId => flag; null when no value is stored
	 */
	public function getDayStartCheckinRequiredSettingsForManagedDepartments(int $userId, ?int $structureId = null): array
	{
		return $this->loadBooleanSettingsForManagedDepartments($userId, NodeSettingsType::DayStartCheckinRequired, $structureId);
	}

	/**
	 * Sets WelcomeBox for all departments where the user is head or deputy head,
	 * cascading down the full subtree.
	 *
	 * @param int|null $structureId Scope the subtree lookup to a specific structure.
	 *                              Pass null (default) to use the default structure.
	 * @return int[] affected node IDs (root departments + their full subtree)
	 */
	public function setWelcomeBoxForMyManagedDepartments(int $userId, bool $value, ?int $structureId = null): array
	{
		return $this->applyBooleanSettingForMyManagedDepartments($userId, NodeSettingsType::WelcomeBox, $value, $structureId);
	}

	/**
	 * Upsert AutoCheckin for all departments where the user is head or deputy head,
	 * cascading down the full subtree.
	 *
	 * @param int|null $structureId Scope the subtree lookup to a specific structure.
	 *                              Pass null (default) to use the default structure.
	 * @return int[] affected node IDs (root departments + their full subtree)
	 */
	public function setAutoCheckinForMyManagedDepartments(int $userId, bool $value, ?int $structureId = null): array
	{
		return $this->applyBooleanSettingForMyManagedDepartments($userId, NodeSettingsType::AutoCheckin, $value, $structureId);
	}

	/**
	 * Upsert "mandatory check-in at day start" for all departments where the user is head
	 * or deputy head, cascading down the full subtree. Same toggle is used for both enable
	 * and disable — the new value is propagated to all nested DEPARTMENT nodes.
	 *
	 * @param int|null $structureId Scope the subtree lookup to a specific structure.
	 *                              Pass null (default) to use the default structure.
	 * @return int[] affected node IDs (root departments + their full subtree)
	 */
	public function setDayStartCheckinRequiredForMyManagedDepartments(int $userId, bool $value, ?int $structureId = null): array
	{
		return $this->applyBooleanSettingForMyManagedDepartments($userId, NodeSettingsType::DayStartCheckinRequired, $value, $structureId);
	}

	/**
	 * Get AiReports setting for departments where the user is head or deputy head (no subtree).
	 *
	 * @return array<int, ?bool> nodeId => flag; null when no value is stored
	 * @throws \Bitrix\HumanResources\Exception\WrongStructureItemException
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	public function getAiReportsSettingsForManagedDepartments(int $userId, ?int $structureId = null): array
	{
		return $this->loadBooleanSettingsForManagedDepartments($userId, NodeSettingsType::AiReports, $structureId);
	}

	/**
	 * Upsert AiReports for all departments where the user is head or deputy head,
	 * cascading down the full subtree.
	 *
	 * @param int|null $structureId Scope the subtree lookup to a specific structure.
	 *                              Pass null (default) to use the default structure.
	 * @return int[] affected node IDs (root departments + their full subtree)
	 * @throws \Bitrix\HumanResources\Exception\WrongStructureItemException
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	public function setAiReportsForMyManagedDepartments(int $userId, bool $value, ?int $structureId = null): array
	{
		// OnAiReportsEnabled is dispatched from NodeSettingsRepository on every AiReports=Y write —
		// covers both this cascade-set and the inheritance path on node creation.
		return $this->applyBooleanSettingForMyManagedDepartments($userId, NodeSettingsType::AiReports, $value, $structureId);
	}

	/**
	 * Returns users grouped by their highest-priority department role across all departments
	 * that have AiReports enabled.
	 *
	 * Priority: Head > DeputyHead > Employee.
	 * Each user appears in exactly one role bucket.
	 *
	 * @return array<string, int[]> NodeMemberRole->value => list of user IDs
	 * @throws \Bitrix\HumanResources\Exception\WrongStructureItemException
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	public function getUsersByMaxRoleWithAiReportsEnabled(): array
	{
		// Query 1: node IDs with AiReports enabled
		$nodeIds = $this->nodeSettingsRepository->getNodeIdsByTypeAndValue(
			NodeSettingsType::AiReports,
			NodeSettingsType::BOOLEAN_TRUE,
		);

		if (empty($nodeIds))
		{
			return [];
		}

		// Query 2: (entityId, roleId) pairs for active USER members of those nodes
		$rows = InternalContainer::getNodeMemberRepository()
			->getEntityIdsWithRoleIdsByNodeIds($nodeIds, active: true)
		;

		if (empty($rows))
		{
			return [];
		}

		$roleHelper = Container::getRoleHelperService();
		$roleIdToInfo = array_filter([
			$roleHelper->getHeadRoleId()     => ['priority' => 2, 'key' => NodeMemberRole::Head->value],
			$roleHelper->getDeputyRoleId()   => ['priority' => 1, 'key' => NodeMemberRole::DeputyHead->value],
			$roleHelper->getEmployeeRoleId() => ['priority' => 0, 'key' => NodeMemberRole::Employee->value],
		]);

		// Keep the highest-priority role per user
		$maxRoleByUser = [];
		foreach ($rows as $row)
		{
			$userId = (int)$row['ENTITY_ID'];
			$roleId = (int)$row['ROLE_ID'];

			if (!isset($roleIdToInfo[$roleId]))
			{
				continue;
			}

			$info = $roleIdToInfo[$roleId];
			if (!isset($maxRoleByUser[$userId]) || $info['priority'] > $maxRoleByUser[$userId]['priority'])
			{
				$maxRoleByUser[$userId] = $info;
			}
		}

		$result = [];
		foreach ($maxRoleByUser as $userId => $info)
		{
			$result[$info['key']][] = $userId;
		}

		return $result;
	}

	/**
	 * Returns node IDs of DEPARTMENT nodes where the user is head or deputy head.
	 *
	 * @return int[]
	 */
	private function getManagedDepartmentNodeIds(int $userId, ?int $structureId = null): array
	{
		return InternalContainer::getNodeMemberService()
			->getManagedDepartmentNodes($userId, $structureId)
			->getUniqueNodeIds()
		;
	}

	/**
	 * Returns a boolean NodeSetting for all DEPARTMENT nodes where the user is a direct member,
	 * grouped by the user's role (MEMBER_HEAD, MEMBER_DEPUTY_HEAD, MEMBER_EMPLOYEE).
	 *
	 * @return array<string, array<int, ?bool>>
	 */
	private function loadBooleanSettingsForMyDepartments(int $userId, NodeSettingsType $type): array
	{
		$members = Container::getNodeMemberRepository()
			->findAllByEntityIdAndEntityTypeAndNodeType(
				$userId,
				MemberEntityType::USER,
				NodeEntityType::DEPARTMENT,
			)
		;

		if ($members->count() === 0)
		{
			return [];
		}

		$roleIdToKey = Container::getRoleHelperService()->getDepartmentRoleIdToMemberRoleMap();

		$nodeIdsByRole = [];
		foreach ($members as $member)
		{
			$roleId = $member->roles[0] ?? null;
			if ($roleId === null || !isset($roleIdToKey[$roleId]))
			{
				continue;
			}

			$nodeIdsByRole[$roleIdToKey[$roleId]][] = $member->nodeId;
		}

		if (empty($nodeIdsByRole))
		{
			return [];
		}

		$settingsByNodeId = $this->loadBooleanSettingsByNodeId($members->getUniqueNodeIds(), $type);

		$result = [];
		foreach ($nodeIdsByRole as $roleKey => $nodeIds)
		{
			$bucket = [];
			foreach ($nodeIds as $nodeId)
			{
				$bucket[$nodeId] = $settingsByNodeId[$nodeId] ?? null;
			}

			$result[$roleKey] = $bucket;
		}

		return $result;
	}

	/**
	 * Returns a boolean NodeSetting for DEPARTMENT nodes where the user is head or deputy head (no subtree).
	 *
	 * @return array<int, ?bool> nodeId => flag; null when no value is stored
	 */
	private function loadBooleanSettingsForManagedDepartments(int $userId, NodeSettingsType $type, ?int $structureId): array
	{
		$managedNodeIds = $this->getManagedDepartmentNodeIds($userId, $structureId);

		if (empty($managedNodeIds))
		{
			return [];
		}

		$settingsByNodeId = $this->loadBooleanSettingsByNodeId($managedNodeIds, $type);

		$result = [];
		foreach ($managedNodeIds as $nodeId)
		{
			$result[$nodeId] = $settingsByNodeId[$nodeId] ?? null;
		}

		return $result;
	}

	/**
	 * Upserts a boolean NodeSetting for all departments where the user is head or deputy head,
	 * cascading down the full subtree of DEPARTMENT nodes.
	 *
	 * @return int[] affected node IDs (root departments + their full subtree)
	 */
	private function applyBooleanSettingForMyManagedDepartments(int $userId, NodeSettingsType $type, bool $value, ?int $structureId): array
	{
		$rootNodeIds = $this->getManagedDepartmentNodeIds($userId, $structureId);

		if (empty($rootNodeIds))
		{
			return [];
		}

		$subtreeNodeIds = InternalContainer::getNodeRepository()
			->findChildrenByNodeIds(
				$rootNodeIds,
				structureId: $structureId,
				depthLevel: DepthLevel::FULL,
				nodeTypes: [NodeEntityType::DEPARTMENT],
			)
			->getIds()
		;

		$allNodeIds = array_unique(array_merge($rootNodeIds, $subtreeNodeIds));

		InternalContainer::getNodeSettingsService()
			->setBooleanSettingForNodes($allNodeIds, $type, $value)
		;

		return $allNodeIds;
	}

	/**
	 * @param int[] $nodeIds
	 * @return array<int, ?bool> nodeId => flag (only nodes that have a stored value)
	 */
	private function loadBooleanSettingsByNodeId(array $nodeIds, NodeSettingsType $type): array
	{
		$collection = $this->nodeSettingsRepository->getByNodesAndTypes($nodeIds, [$type]);

		$result = [];
		foreach ($collection as $setting)
		{
			$result[$setting->nodeId] = NodeSettingsType::booleanFromString($setting->settingsValue);
		}

		return $result;
	}
}
