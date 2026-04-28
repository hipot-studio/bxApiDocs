<?php

namespace Bitrix\HumanResources\Repository\Access;

use Bitrix\HumanResources\Access\Permission\PermissionDictionary;
use Bitrix\HumanResources\Access\Permission\PermissionVariablesDictionary;
use Bitrix\HumanResources\Access\StructureAccessController;
use Bitrix\HumanResources\Enum\DepthLevel;
use Bitrix\HumanResources\Enum\NodeActiveFilter;
use Bitrix\HumanResources\Item\Collection\NodeCollection;
use Bitrix\HumanResources\Model\NodeTable;
use Bitrix\HumanResources\Model\NodeMemberTable;
use Bitrix\HumanResources\Public\Service\Container as PublicContainer;
use Bitrix\HumanResources\Type\NodeEntityType;
use Bitrix\HumanResources\Type\StructureAction;
use Bitrix\HumanResources\Item;
use Bitrix\HumanResources\Item\Node;
use Bitrix\HumanResources\Repository\NodeRepository;
use Bitrix\HumanResources\Type\MemberEntityType;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\SystemException;

/**
 * For permission-aware node queries, use the public node service methods.
 * @see \Bitrix\HumanResources\Public\Service\Container::getNodeService()
 * @see \Bitrix\HumanResources\Public\Service\NodeService
 */
final class PermissionRestrictedNodeRepository extends NodeRepository
{
	private StructureAccessController $accessController;
	private StructureAction $structureAction;
	private readonly int $structurePermissionId;
	private int $userId;

	public function __construct(StructureAction $structureAction = StructureAction::ViewAction)
	{
		parent::__construct();

		$this->userId = (int)CurrentUser::get()->getId();
		$this->setUserId($this->userId);
		$this->setPermissionSettings($structureAction);
	}

	public function setUserId(int $userId): void
	{
		$this->accessController = StructureAccessController::getInstance($userId);
	}

	private function setPermissionSettings(StructureAction $structureAction): void
	{
		$this->structureAction = $structureAction;
		$accessibleUser = $this->accessController->getUser();
		$structureAccessInfo = $structureAction->getAccessInfoByEntityType();
		if ($structureAccessInfo->permissionId === PermissionDictionary::HUMAN_RESOURCES_STRUCTURE_VIEW)
		{
			$this->structurePermissionId = PermissionDictionary::HUMAN_RESOURCES_STRUCTURE_VIEW;

			return;
		}

		$permissionValue = (int)$accessibleUser->getPermission($structureAccessInfo->permissionId);
		$viewPermissionValue = (int)$accessibleUser->getPermission(PermissionDictionary::HUMAN_RESOURCES_STRUCTURE_VIEW);

		if (
			$viewPermissionValue <= $permissionValue
		)
		{
			$this->structurePermissionId = PermissionDictionary::HUMAN_RESOURCES_STRUCTURE_VIEW;

			return;
		}

		$this->structurePermissionId = $structureAccessInfo->permissionId;
	}

	/**
	 * Internal repository method for getting a Node by id. Use public node service instead.
	 *
	 * @param int $nodeId
	 * @param bool $needDepth
	 *
	 * @return Node|null
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 *
	 * @deprecated Internal. Use \Bitrix\HumanResources\Public\Service\Container::getNodeService() and call NodeService::getById() instead.
	 * @see \Bitrix\HumanResources\Public\Service\Container::getNodeService()
	 * @see \Bitrix\HumanResources\Public\Service\NodeService::getById()
	 *
	 */
	public function getById(int $nodeId, bool $needDepth = false): ?Item\Node
	{
		return PublicContainer::getNodeService()->getById(
			nodeId: $nodeId,
			structureAction: $this->structureAction,
		);
	}

	/**
	 * Internal repository method for getting a Node by id with depth. Use public node service instead.
	 *
	 * @deprecated Internal. Use \Bitrix\HumanResources\Public\Service\Container::getNodeService() and call NodeService::getById() instead.
	 * @see \Bitrix\HumanResources\Public\Service\Container::getNodeService()
	 * @see \Bitrix\HumanResources\Public\Service\NodeService::getById()
	 *
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function getByIdWithDepth(int $nodeId): ?Item\Node
	{
		return PublicContainer::getNodeService()->getById(
			nodeId: $nodeId,
			needDepth: true,
			structureAction: $this->structureAction,
		);
	}

	/**
	 * Internal repository method for getting child node IDs for a given nodeId (returns only departments).
	 *
	 * @param int $nodeId
	 *
	 * @return int[]
	 *@see \Bitrix\HumanResources\Public\Service\NodeService::findChildrenByNodeIds()
	 *
	 * @deprecated Deprecated. Use \Bitrix\HumanResources\Public\Service\Container::getNodeService() and call NodeService::findAllChildrenByNodeId() instead.
	 * @see \Bitrix\HumanResources\Public\Service\Container::getNodeService()
	 */
	public function getAllChildIdsByNodeId(int $nodeId): array
	{
		return PublicContainer::getNodeService()->findChildrenByNodeIds(
			nodeIds: [$nodeId],
			nodeTypes: [NodeEntityType::DEPARTMENT],
			structureAction: $this->structureAction,
		)->getIds();
	}

	/**
	 * Internal repository method for getting child nodeCollection for a given node (returns only departments).
	 *
	 * @deprecated Deprecated. Use \Bitrix\HumanResources\Public\Service\Container::getNodeService() and call NodeService::findAllChildrenByNodeId() instead.
	 * @see \Bitrix\HumanResources\Public\Service\Container::getNodeService()
	 * @see \Bitrix\HumanResources\Public\Service\NodeService::findChildrenByNodeIds()
	 */
	public function getChildOf(
		Node $node,
		DepthLevel|int $depthLevel = DepthLevel::FIRST,
		NodeActiveFilter $activeFilter = NodeActiveFilter::ONLY_GLOBAL_ACTIVE,
	): Item\Collection\NodeCollection
	{
		return PublicContainer::getNodeService()->findChildrenByNodeIds(
			nodeIds: [$node->id],
			nodeTypes: [NodeEntityType::DEPARTMENT],
			depthLevel: $depthLevel,
			structureAction: $this->structureAction,
			activeFilter: $activeFilter,
		);
	}

	/**
	 * Internal repository method for getting parent nodeCollection.
	 *
	 * @deprecated Deprecated. Use \Bitrix\HumanResources\Public\Service\Container::getNodeService() and call NodeService::findAllParentsByNodeId() instead.
	 * @see \Bitrix\HumanResources\Public\Service\Container::getNodeService()
	 * @see \Bitrix\HumanResources\Public\Service\NodeService::findParentsByNodeId()
	 */
	public function getParentOf(
		Node $node,
		DepthLevel|int $depthLevel = DepthLevel::FIRST,
	): Item\Collection\NodeCollection
	{
		return PublicContainer::getNodeService()->findParentsByNodeId(
			nodeId: $node->id,
			depthLevel: $depthLevel,
			structureAction: $this->structureAction,
		);
	}

	public function findAllByUserIdAndRoleId(
		int $userId,
		int $roleId,
		NodeActiveFilter $activeFilter = NodeActiveFilter::ONLY_GLOBAL_ACTIVE,
	): Item\Collection\NodeCollection
	{
		if ($this->userId <= 0)
		{
			return new Item\Collection\NodeCollection();
		}

		$permissionValue = $this->getPermissionValue();
		if (
			!$permissionValue
			|| !in_array($permissionValue, $this->getAvailablePermissionValues(), true)
		)
		{
			return new Item\Collection\NodeCollection();
		}

		if (
			$permissionValue === PermissionVariablesDictionary::VARIABLE_ALL
			|| $this->accessController->getUser()->getUserId() === $userId
		)
		{
			return parent::findAllByUserIdAndRoleId($userId, $roleId, $activeFilter);
		}

		$nodeItems = new Item\Collection\NodeCollection();
		$query =
			NodeTable::query()
				->setSelect(['*'])
				->addSelect('ACCESS_CODE')
				->addSelect('CHILD_NODES')
				->where('TYPE', NodeEntityType::DEPARTMENT->value)
				->registerRuntimeField(
					'nm',
					new Reference(
						'nm',
						NodeMemberTable::class,
						Join::on('this.ID', 'ref.NODE_ID'),
					),
				)
				->where('nm.ENTITY_ID', $userId)
				->where('nm.ENTITY_TYPE', MemberEntityType::USER->name)
				->where('nm.ROLE.ID', $roleId)
				->setCacheTtl(86400)
				->cacheJoins(true)
		;
		$query = $this->setNodeActiveFilter($query, $activeFilter);
		$query = $this->setAdditionalFilterByPermission($query, $permissionValue);

		$result = $query->exec();

		while ($nodeEntity = $result->fetch())
		{
			$node = $this->convertModelArrayToItem($nodeEntity);
			$nodeItems->add($node);
		}

		return $nodeItems;
	}

	/**
	 * Finds all departments for a given user.
	 *
	 * @deprecated Deprecated. Use \Bitrix\HumanResources\Public\Service\Container::getNodeService() and call NodeService::findAllByNodeMemberId() instead.
	 * @see \Bitrix\HumanResources\Public\Service\Container::getNodeService()
	 * @see \Bitrix\HumanResources\Public\Service\NodeService::findAllByMemberEntityId()
	 */
	public function findAllByUserId(int $userId, NodeActiveFilter $activeFilter = NodeActiveFilter::ONLY_GLOBAL_ACTIVE): Item\Collection\NodeCollection
	{
		return PublicContainer::getNodeService()->findAllByMemberEntityId(
			memberEntityId: $userId,
			structureAction: $this->structureAction,
			nodeActiveFilter: $activeFilter,
		);
	}

	public function getByAccessCode(string $accessCode): ?Item\Node
	{
		return PublicContainer::getNodeService()->getByAccessCode(
			accessCode: $accessCode,
			structureAction: $this->structureAction,
		);
	}

	/**
	 * Internal repository method for getting all departments.
	 *
	 * @deprecated Deprecated. Use \Bitrix\HumanResources\Public\Service\Container::getNodeService() and call NodeService::findAll() instead.
	 * @see \Bitrix\HumanResources\Public\Service\Container::getNodeService()
	 * @see \Bitrix\HumanResources\Public\Service\NodeService::findAll()
	 */
	public function getAllByStructureId(
		int $structureId,
		NodeActiveFilter $activeFilter = NodeActiveFilter::ONLY_GLOBAL_ACTIVE,
	): Item\Collection\NodeCollection
	{
		return PublicContainer::getNodeService()->findAll(
			structureId: $structureId,
			structureAction: $this->structureAction,
			activeFilter: $activeFilter,
		);
	}

	/**
	 * Internal repository method for getting all departments (paginated).
	 *
	 * @deprecated Deprecated. Use \Bitrix\HumanResources\Public\Service\Container::getNodeService() and call NodeService::findAll() instead.
	 * @see \Bitrix\HumanResources\Public\Service\Container::getNodeService()
	 * @see \Bitrix\HumanResources\Public\Service\NodeService::findAll()
	 */
	public function getAllPagedByStructureId(
		int $structureId,
		int $limit = 10,
		int $offset = 0,
		NodeActiveFilter $activeFilter = NodeActiveFilter::ONLY_GLOBAL_ACTIVE,
	): Item\Collection\NodeCollection
	{
		return PublicContainer::getNodeService()->findAll(
			structureId: $structureId,
			structureAction: $this->structureAction,
			activeFilter: $activeFilter,
			limit: $limit,
			offset: $offset,
		);
	}

	/**
	 * Internal repository method for getting nodes by access codes. Use public node service instead.
	 *
	 * @deprecated Internal. Use \Bitrix\HumanResources\Public\Service\Container::getNodeService() and call NodeService::getByAccessCode() instead.
	 * @see \Bitrix\HumanResources\Public\Service\Container::getNodeService()
	 * @see \Bitrix\HumanResources\Public\Service\NodeService::findAllByName()
	 */
	public function getNodesByName(
		int $structureId,
		?string $name,
		?int $limit = 100,
		?int $parentId = null,
		DepthLevel|int $depth = DepthLevel::FULL,
		bool $strict = false,
		$activeFilter = NodeActiveFilter::ONLY_GLOBAL_ACTIVE,
	): Item\Collection\NodeCollection
	{
		$parentIds = is_null($parentId) ? null : [$parentId];

		return PublicContainer::getNodeService()->findAllByName(
			name: $name,
			structureId: $structureId,
			parentIds: $parentIds,
			depthLevel: $depth,
			strict: $strict,
			structureAction: $this->structureAction,
			activeFilter: $activeFilter,
			limit: $limit,
		);
	}

	/**
	 * Internal repository method for getting child nodeCollection for a given nodeCollection (returns only departments).
	 *
	 * @deprecated Deprecated. Use \Bitrix\HumanResources\Public\Service\Container::getNodeService() and call NodeService::findAllChildrenByNodeIds() instead.
	 * @see \Bitrix\HumanResources\Public\Service\Container::getNodeService()
	 * @see \Bitrix\HumanResources\Public\Service\NodeService::findChildrenByNodeIds()
	 */
	public function getChildOfNodeCollection(
		NodeCollection $nodeCollection,
		DepthLevel|int $depthLevel = DepthLevel::FIRST,
		NodeActiveFilter $activeFilter = NodeActiveFilter::ONLY_GLOBAL_ACTIVE,
	): NodeCollection
	{
		$parentIds = $nodeCollection->getIds();
		if (empty($parentIds))
		{
			return new NodeCollection();
		}

		return PublicContainer::getNodeService()->findChildrenByNodeIds(
			nodeIds: $parentIds,
			nodeTypes: [NodeEntityType::DEPARTMENT],
			depthLevel: $depthLevel,
			structureAction: $this->structureAction,
			activeFilter: $activeFilter,
		);
	}

	/**
	 * Internal repository method for getting nodes by xmlId. Use public node service instead.
	 *
	 * @deprecated Internal. Use \Bitrix\HumanResources\Public\Service\Container::getNodeService() and call NodeService::getByAccessCode() instead.
	 * @see \Bitrix\HumanResources\Public\Service\Container::getNodeService()
	 * @see \Bitrix\HumanResources\Public\Service\NodeService::findAllByXmlId()
	 */
	public function findAllByXmlId(
		string $xmlId,
		NodeActiveFilter $activeFilter = NodeActiveFilter::ONLY_GLOBAL_ACTIVE,
	): Item\Collection\NodeCollection
	{
		return PublicContainer::getNodeService()->findAllByXmlId(
			xmlId: $xmlId,
			structureAction: $this->structureAction,
			activeFilter: $activeFilter,
		);
	}

	/**
	 * Internal repository method for departments by ids.
	 *
	 * @deprecated Deprecated. Use \Bitrix\HumanResources\Public\Service\Container::getNodeService() and call NodeService::findAllChildrenByNodeIds() instead.
	 * @see \Bitrix\HumanResources\Public\Service\Container::getNodeService()
	 * @see \Bitrix\HumanResources\Public\Service\NodeService::findAllByIds()
	 */
	public function findAllByIds(
		array $departmentIds,
		NodeActiveFilter $activeFilter = NodeActiveFilter::ONLY_GLOBAL_ACTIVE,
	): Item\Collection\NodeCollection
	{
		return PublicContainer::getNodeService()->findAllByIds(
			nodeIds: $departmentIds,
			structureAction: $this->structureAction,
			activeFilter: $activeFilter,
		);
	}

	private function getPermissionValue(): int
	{
		if ($this->accessController->getUser()->isAdmin())
		{
			return PermissionVariablesDictionary::VARIABLE_ALL;
		}

		return (int)$this->accessController->getUser()->getPermission($this->structurePermissionId);
	}

	private function getAvailablePermissionValues(): array
	{
		return [
			PermissionVariablesDictionary::VARIABLE_ALL,
			PermissionVariablesDictionary::VARIABLE_SELF_DEPARTMENTS_SUB_DEPARTMENTS,
			PermissionVariablesDictionary::VARIABLE_SELF_DEPARTMENTS,
		];
	}

	private function getAllUserNodeIds(): array
	{
		$userId = $this->accessController->getUser()->getUserId();

		$nodeCollection = parent::findAllByUserId($userId);
		$nodeIds = [];
		foreach ($nodeCollection as $node)
		{
			$nodeIds[] = $node->id;
		}

		return $nodeIds;
	}

	private function setAdditionalFilterByPermission(Query $query, int $permissionValue): Query
	{
		$userNodeIds = $this->getAllUserNodeIds();
		if ($permissionValue === PermissionVariablesDictionary::VARIABLE_SELF_DEPARTMENTS_SUB_DEPARTMENTS)
		{
			$query->whereIn('CHILD_NODES.PARENT_ID', $userNodeIds);
		}

		if ($permissionValue === PermissionVariablesDictionary::VARIABLE_SELF_DEPARTMENTS)
		{
			$query->whereIn('ID', $userNodeIds);
		}

		return $query;
	}
}