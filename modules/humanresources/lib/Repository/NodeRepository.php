<?php

namespace Bitrix\HumanResources\Repository;

use Bitrix\HumanResources\Access\AuthProvider\StructureAuthProvider;
use Bitrix\HumanResources\Command\Structure\Node\NodeOrderCommand;
use Bitrix\HumanResources\Contract;
use Bitrix\HumanResources\Item\Collection\NodeCollection;
use Bitrix\HumanResources\Service\EventSenderService;
use Bitrix\HumanResources\Enum\DepthLevel;
use Bitrix\HumanResources\Enum\EventName;
use Bitrix\HumanResources\Enum\NodeActiveFilter;
use Bitrix\HumanResources\Exception\CreationFailedException;
use Bitrix\HumanResources\Exception\DeleteFailedException;
use Bitrix\HumanResources\Exception\UpdateFailedException;
use Bitrix\HumanResources\Exception\WrongStructureItemException;
use Bitrix\HumanResources\Item;
use Bitrix\HumanResources\Item\Node;
use Bitrix\HumanResources\Model;
use Bitrix\HumanResources\Model\NodePathTable;
use Bitrix\HumanResources\Model\NodeTable;
use Bitrix\HumanResources\Public\Service\Container as PublicContainer;
use Bitrix\HumanResources\Service\Container;
use Bitrix\HumanResources\Type\MemberEntityType;
use Bitrix\HumanResources\Type\NodeEntityType;
use Bitrix\HumanResources\Util\AccessCodeHelper;
use Bitrix\Main;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Error;
use Bitrix\Main\ErrorCollection;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\SystemException;

/**
 * For selecting nodes, use the public node service methods.
 * @see \Bitrix\HumanResources\Public\Service\Container::getNodeService()
 * @see \Bitrix\HumanResources\Public\Service\NodeService
 */
class NodeRepository implements Contract\Repository\NodeRepository
{
	protected readonly Contract\Util\CacheManager $cacheManager;
	protected readonly EventSenderService $eventSenderService;
	private readonly StructureAuthProvider $structureAuthProvider;

	public function __construct()
	{
		$this->cacheManager = Container::getCacheManager();
		$this->cacheManager->setTtl(86400*7);
		$this->structureAuthProvider = Container::getStructureAuthProvider();
		$this->eventSenderService = Container::getEventSenderService();
	}

	public function mapItemToModel(Model\Node $nodeEntity, Node $node): Model\Node
	{
		return $nodeEntity
			->setStructureId($node->structureId)
			->setType($node->type->name)
			->setName($node->name)
			->setCreatedBy($node->createdBy)
			->setXmlId($node->xmlId)
			->setParentId($node->parentId)
			->setActive($node->active)
			->setGlobalActive($node->globalActive)
			->setSort($node->sort)
			->setDescription($node->description)
			->setColorName($node->colorName)
		;
	}

	protected function convertModelToItem(Model\Node $node): Node
	{
		$nodeId = $node->getId();
		$accessCode = $node->getAccessCode()?->current();
		$depth = $node->getChildNodes()?->current();

		return new Node(
			name: $node->getName(),
			type: NodeEntityType::tryFrom($node->getType()),
			structureId: $node->getStructureId(),
			accessCode: $accessCode ? $accessCode->getAccessCode() : AccessCodeHelper::makeCodeByTypeAndId($nodeId),
			id: $nodeId,
			parentId: $node->getParentId(),
			depth: $depth ? $depth->getDepth() : null,
			createdBy: $node->getCreatedBy(),
			createdAt: $node->getCreatedAt(),
			updatedAt: $node->getUpdatedAt(),
			xmlId: $node->getXmlId(),
			active: $node->getActive(),
			globalActive: $node->getGlobalActive(),
			sort: $node->getSort(),
			description: $node->getDescription(),
			colorName: $node->getColorName(),
		);
	}

	protected function convertModelArrayToItem(array $node): Node
	{
		$accessCode =
			$node['HUMANRESOURCES_MODEL_NODE_ACCESS_CODE_ACCESS_CODE']
			?? AccessCodeHelper::makeCodeByTypeAndId((int)($node['ID'] ?? 0))
		;

		return new Node(
			name: $node['NAME'] ?? null,
			type: NodeEntityType::tryFrom($node['TYPE'] ?? '') ?? null,
			structureId: $node['STRUCTURE_ID'] ?? null,
			accessCode: $accessCode,
			id: $node['ID'] ?? null,
			parentId: $node['PARENT_ID'] ?? null,
			depth: $node['HUMANRESOURCES_MODEL_NODE_CHILD_NODES_DEPTH'] ?? null,
			createdBy: $node['CREATED_BY'] ?? null,
			createdAt: $node['CREATED_AT'] ?? null,
			updatedAt: $node['UPDATED_AT'] ?? null,
			xmlId: $node['XML_ID'] ?? null,
			active: ($node['ACTIVE'] ?? '') === 'Y',
			globalActive: ($node['GLOBAL_ACTIVE'] ?? '') === 'Y',
			sort: $node['SORT'] ?? 0,
			description: $node['DESCRIPTION'] ?? null,
			colorName: $node['COLOR_NAME'] ?? null,
		);
	}

	/**
	 * @param Node $node
	 *
	 * @return Node
	 * @throws CreationFailedException
	 * @throws ArgumentException
	 * @throws SystemException
	 */
	public function create(Node $node): Node
	{
		if (is_null($node->structureId))
		{
			throw (new CreationFailedException())->setErrors(
				new ErrorCollection([new Error('No structure for node')]),
			);
		}
		$nodeEntity = NodeTable::getEntity()->createObject();
		$currentUserId = CurrentUser::get()->getId();
		$node->createdBy = (int)$currentUserId;

		$this->prepareSort($node);

		$result = $this->mapItemToModel($nodeEntity, $node)
			->save()
		;

		if (!$result->isSuccess())
		{
			throw (new CreationFailedException())
				->setErrors($result->getErrorCollection())
			;
		}

		$node->id = $result->getId();
		NodePathTable::appendNode($node->id, $node->parentId);

		$this->eventSenderService->send(
			EventName::OnNodeAdded,
			[
				'node' => $node,
			],
		);
		$this->structureAuthProvider->recalculateCodesForNode($node);

		return $node;
	}

	/**
	 * @param Node $node
	 *
	 * @return Node
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 * @throws UpdateFailedException|WrongStructureItemException
	 */
	public function update(Node $node): Node
	{
		if (!$node->id)
		{
			return $node;
		}

		$nodeCache = $this->getById($node->id);

		if (!$nodeCache)
		{
			throw (new UpdateFailedException())->addError(new Error("Node with id $node->id dont exist"));
		}

		$updatedField = [];
		if ($node->name && $node->name !== $nodeCache->name)
		{
			$nodeCache->name = $node->name;
			$updatedField['name'] = $node->name;
		}

		if ($node->type && $node->type !== $nodeCache->type)
		{
			$nodeCache->type = $node->type;
			$updatedField['type'] = $node->type;
		}

		$parentChanged = false;
		if ($node->parentId !== $nodeCache->parentId)
		{
			$nodeCache->parentId = $node->parentId;
			$updatedField['parentId'] = $node->parentId;
			$parentChanged = true;
		}

		if ($node->xmlId && $node->xmlId !== $nodeCache->xmlId)
		{
			$nodeCache->xmlId = $node->xmlId;
			$updatedField['xmlId'] = $node->xmlId;
		}

		if (
			(!is_null($node->active) && $node->active !== $nodeCache->active)
			|| $parentChanged
		)
		{
			$nodeCache->active = $node->active;

			$updateGlobalActiveStatus = true;
			$globalActive = true;
			if (
				$node->active === true
				|| $parentChanged
			)
			{
				foreach (PublicContainer::getNodeService()->findParentsByNodeId($node->id) as $parent)
				{
					if (
						$parent->id !== $nodeCache->id
						&& $parent->active === false
					)
					{
						$globalActive = false;
						$updateGlobalActiveStatus = false;

						break;
					}
				}
			}

			if ($node->active === false)
			{
				$globalActive = false;
			}

			if (
				$parentChanged
				|| $updateGlobalActiveStatus
			)
			{
				$nodeCache->globalActive = $node->globalActive = $globalActive;
				$this->setGlobalActiveToNodeAndChildren($nodeCache, $globalActive);
			}
			$updatedField['active'] = $node->active;
		}

		if (!is_null($node->globalActive) && $node->globalActive !== $nodeCache->globalActive)
		{
			$nodeCache->globalActive = $node->globalActive;
		}

		if ($node->sort !== null && $node->sort !== $nodeCache->sort)
		{
			$nodeCache->sort = $node->sort;
			$updatedField['sort'] = $node->sort;
		}

		if ($node->description !== null && $node->description !== $nodeCache->description)
		{
			$nodeCache->description = $node->description === '' ? null : $node->description;
			$updatedField['description'] = $node->description;
		}

		if ($node->colorName !== null && $node->colorName !== $nodeCache->colorName)
		{
			$nodeCache->colorName = $node->colorName === '' ? null : $node->colorName;
			$updatedField['colorName'] = $node->colorName;
		}

		if (!empty($updatedField))
		{
			$nodeEntity = NodeTable::getById($nodeCache->id)->fetchObject();

			$result = $this->mapItemToModel($nodeEntity, $nodeCache)
				->save()
			;

			if (!$result->isSuccess())
			{
				throw (new UpdateFailedException())
					->setErrors($result->getErrorCollection())
				;
			}

			$this->removeNodeCache($nodeCache->id);
			$this->eventSenderService->send(EventName::OnNodeUpdated, [
				'node' => $nodeCache,
				'fields' => $updatedField,
			]);
			$this->structureAuthProvider->recalculateCodesForNode($node);
		}

		return $node;
	}

	/**
	 * Finds all departments for a given user.
	 *
	 * @deprecated Deprecated. Use \Bitrix\HumanResources\Public\Service\Container::getNodeService() and call NodeService::findAllByNodeMemberId() instead.
	 * @see \Bitrix\HumanResources\Public\Service\Container::getNodeService()
	 * @see \Bitrix\HumanResources\Public\Service\NodeService::findAllByMemberEntityId()
	 */
	public function findAllByUserId(int $userId, NodeActiveFilter $activeFilter = NodeActiveFilter::ONLY_GLOBAL_ACTIVE): NodeCollection
	{
		return PublicContainer::getNodeService()->findAllByMemberEntityId(
			memberEntityId: $userId,
			nodeActiveFilter: $activeFilter,
		);
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
	public function getById(int $nodeId, bool $needDepth = false): ?Node
	{
		return PublicContainer::getNodeService()->getById($nodeId, $needDepth);
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
	public function getByIdWithDepth(int $nodeId): ?Node
	{
		return PublicContainer::getNodeService()->getById($nodeId, needDepth: true);
	}

	private function removeNodeCache(int $nodeId): void
	{
		$nodeCacheKey = sprintf(self::NODE_ENTITY_CACHE_KEY, $nodeId);
		$this->cacheManager->clean($nodeCacheKey);
	}

	/**
	 * Internal repository method for getting child node IDs for a given nodeId (returns only departments).
	 *
	 * @deprecated Deprecated. Use \Bitrix\HumanResources\Public\Service\Container::getNodeService() and call NodeService::findAllChildrenByNodeId() instead.
	 * @see \Bitrix\HumanResources\Public\Service\Container::getNodeService()
	 * @see \Bitrix\HumanResources\Public\Service\NodeService::findChildrenByNodeIds()
	 */
	public function getAllChildIdsByNodeId(int $nodeId): array
	{
		return PublicContainer::getNodeService()->findChildrenByNodeIds(
			nodeIds: [$nodeId],
			nodeTypes: [NodeEntityType::DEPARTMENT],
		)->getIds();
	}

	/**
	 * Internal repository method for getting parent nodeCollection.
	 *
	 * @deprecated Deprecated. Use \Bitrix\HumanResources\Public\Service\Container::getNodeService() and call NodeService::findAllParentsByNodeId() instead.
	 * @see \Bitrix\HumanResources\Public\Service\Container::getNodeService()
	 * @see \Bitrix\HumanResources\Public\Service\NodeService::findParentsByNodeId()
	 */
	public function getParentOf(
		Item\Node $node,
		DepthLevel|int $depthLevel = DepthLevel::FIRST,
	): NodeCollection
	{
		return PublicContainer::getNodeService()->findParentsByNodeId(
			$node->id,
			depthLevel: $depthLevel,
		);
	}

	/**
	 * Internal repository method for getting child nodeCollection for a given node (returns only departments).
	 *
	 * @deprecated Deprecated. Use \Bitrix\HumanResources\Public\Service\Container::getNodeService() and call NodeService::findAllChildrenByNodeId() instead.
	 * @see \Bitrix\HumanResources\Public\Service\Container::getNodeService()
	 * @see \Bitrix\HumanResources\Public\Service\NodeService::findChildrenByNodeIds()
	 */
	public function getChildOf(
		Item\Node $node,
		DepthLevel|int $depthLevel = DepthLevel::FIRST,
		NodeActiveFilter $activeFilter = NodeActiveFilter::ONLY_GLOBAL_ACTIVE,
	): NodeCollection
	{
		return PublicContainer::getNodeService()->findChildrenByNodeIds(
			nodeIds: [$node->id],
			nodeTypes: [NodeEntityType::DEPARTMENT],
			depthLevel: $depthLevel,
			activeFilter: $activeFilter,
		);
	}

	/**
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\HumanResources\Exception\WrongStructureItemException
	 * @throws \Bitrix\Main\SystemException
	 */
	public function findAllByUserIdAndRoleId(int $userId, int $roleId, NodeActiveFilter $activeFilter = NodeActiveFilter::ONLY_GLOBAL_ACTIVE): NodeCollection
	{
		$nodeItems = new NodeCollection();
		$query =
			NodeTable::query()
				->setSelect(['*'])
				->addSelect('ACCESS_CODE')
				->addSelect('CHILD_NODES')
				->registerRuntimeField(
					'nm',
					new Reference(
						'nm',
						Model\NodeMemberTable::class,
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
		$result = $query->exec();

		while ($nodeEntity = $result->fetch())
		{
			$node = $this->convertModelArrayToItem($nodeEntity);
			$nodeItems->add($node);
		}

		return $nodeItems;
	}

	/**
	 * Internal repository method for getting a Node by access code. Use public node service instead.
	 *
	 * @deprecated Internal. Use \Bitrix\HumanResources\Public\Service\Container::getNodeService() and call NodeService::getByAccessCode() instead.
	 * @see \Bitrix\HumanResources\Public\Service\Container::getNodeService()
	 * @see \Bitrix\HumanResources\Public\Service\NodeService::getByAccessCode()
	 *
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function getByAccessCode(string $accessCode): ?Node
	{
		return PublicContainer::getNodeService()->getByAccessCode($accessCode);
	}

	/**
	 * Internal repository method for getting a root Node. Use public node service instead.
	 *
	 * @deprecated Internal. Use \Bitrix\HumanResources\Public\Service\Container::getNodeService() and call NodeService::getRootNode() instead.
	 * @see \Bitrix\HumanResources\Public\Service\Container::getNodeService()
	 * @see \Bitrix\HumanResources\Public\Service\NodeService::getRootNode()
	 *
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function getRootNodeByStructureId(int $structureId): ?Node
	{
		return PublicContainer::getNodeService()->getRootNode($structureId);
	}

	/**
	 * Internal repository method for getting all departments.
	 *
	 * @deprecated Deprecated. Use \Bitrix\HumanResources\Public\Service\Container::getNodeService() and call NodeService::findAll() instead.
	 * @see \Bitrix\HumanResources\Public\Service\Container::getNodeService()
	 * @see \Bitrix\HumanResources\Public\Service\NodeService::findAll()
	 */
	public function getAllByStructureId(int $structureId, NodeActiveFilter $activeFilter = NodeActiveFilter::ONLY_GLOBAL_ACTIVE): NodeCollection
	{
		return PublicContainer::getNodeService()->findAll(
			structureId:  $structureId,
			limit: 0,
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
	public function getAllPagedByStructureId(int $structureId, int $limit = 10, int $offset = 0, NodeActiveFilter $activeFilter = NodeActiveFilter::ONLY_GLOBAL_ACTIVE): NodeCollection
	{
		return PublicContainer::getNodeService()->findAll(
			structureId: $structureId,
			activeFilter: $activeFilter,
			limit: $limit,
			offset: $offset,
		);
	}

	public function hasChild(Item\Node $node): bool
	{
		$nodeQuery =
			NodeTable::query()
				->where('TYPE', NodeEntityType::DEPARTMENT->value)
				->where('CHILD_NODES.PARENT_ID', $node->id)
				->where('CHILD_NODES.DEPTH', 1)
				->setLimit(1)
				->exec()
		;

		return (bool)$nodeQuery->fetch();
	}

	public function isAncestor(Node $node, Node $targetNode): bool
	{
		if (
			!is_null($node->depth)
			&& !is_null($targetNode->depth)
			&& $node->depth >= $targetNode->depth
		)
		{
			return false;
		}

		$nodePathQuery = NodePathTable::query()
			->where('PARENT_ID', $node->id)
			->where('CHILD_ID', $targetNode->id)
			->setLimit(1)
			->exec()
		;

		return (bool)$nodePathQuery->fetch();
	}

	/**
	 * Delete a node and all associated data from the database.
	 *
	 * @param int $nodeId
	 *
	 * @return void
	 * @throws ArgumentException
	 * @throws SystemException
	 * @throws ObjectPropertyException|DeleteFailedException
	 */
	public function deleteById(int $nodeId): void
	{
		$node = $this->getById($nodeId);
		$this->structureAuthProvider->recalculateCodesForNode($node);
		$result = NodeTable::delete($nodeId);
		if (!$result->isSuccess())
		{
			throw (new DeleteFailedException())
				->setErrors($result->getErrorCollection())
			;
		}

		$this->eventSenderService->send(EventName::OnNodeDeleted, [
			'node' => $node,
		]);

		$this->removeNodeCache($nodeId);
	}

	/**
	 * Internal repository method for getting nodes by access codes. Use public node service instead.
	 *
	 * @deprecated Internal. Use \Bitrix\HumanResources\Public\Service\Container::getNodeService() and call NodeService::getByAccessCode() instead.
	 * @see \Bitrix\HumanResources\Public\Service\Container::getNodeService()
	 * @see \Bitrix\HumanResources\Public\Service\NodeService::findAllByAccessCodes()
	 */
	public function findAllByAccessCodes(array $departments): NodeCollection
	{
		return PublicContainer::getNodeService()->findAllByAccessCodes(
			accessCodes: $departments
		);
	}

	/**
	 * Internal repository method for getting departments by name. Use public node service instead.
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
		NodeActiveFilter $activeFilter = NodeActiveFilter::ONLY_GLOBAL_ACTIVE,
	): NodeCollection
	{
		return PublicContainer::getNodeService()->findAllByName(
			name: $name,
			structureId: $structureId,
			parentIds: $parentId !== null ? [$parentId] : null,
			depthLevel: $depth,
			strict: $strict,
			activeFilter: $activeFilter,
			limit: $limit,
		);
	}

	protected function convertModelArrayToItemByCollection(Model\NodeCollection $models): NodeCollection
	{
		return new NodeCollection(
			...array_map([$this, 'convertModelToItem'],
			$models->getAll(),
		));
	}

	protected function convertModelArrayToItemByArray(array $nodeModelArray): NodeCollection
	{
		return new NodeCollection(
			...array_map([$this, 'convertModelArrayToItem'],
			$nodeModelArray,
		));
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
	public function findAllByXmlId(string $xmlId, NodeActiveFilter $activeFilter = NodeActiveFilter::ONLY_GLOBAL_ACTIVE): NodeCollection
	{
		return PublicContainer::getNodeService()->findAllByXmlId(
			xmlId: $xmlId,
			activeFilter: $activeFilter,
		);
	}

	protected function setNodeActiveFilter(Query $query, NodeActiveFilter $activeFilter): Query
	{
		return match ($activeFilter)
		{
			NodeActiveFilter::ONLY_ACTIVE => $query->where('ACTIVE', true),
			NodeActiveFilter::ONLY_GLOBAL_ACTIVE => $query->where('GLOBAL_ACTIVE', true),
			default => $query,
		};
	}

	/**
	 * @throws UpdateFailedException
	 * @throws ArgumentException
	 * @throws WrongStructureItemException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	private function setGlobalActiveToNodeAndChildren(Node $parentNode, bool $active): void
	{
		$childCollection = $this->getChildOf(
			$parentNode,
			DepthLevel::FULL,
			NodeActiveFilter::ALL,
		);
		$nodeIdsToChangeGlobalActive = [];
		$inactiveParentIds = [];
		foreach ($childCollection as $child)
		{
			if ($active === false)
			{
				$nodeIdsToChangeGlobalActive[] = $child->id;

				continue;
			}

			if ($child->id === $parentNode->id)
			{
				$child->active = $active;
			}

			if (
				$child->active === true
				&& !in_array($child->parentId, $inactiveParentIds, true)
			)
			{
				$nodeIdsToChangeGlobalActive[] = $child->id;
			}

			if (
				$child->active === false
				|| in_array($child->parentId, $inactiveParentIds, true)
			)
			{
				$inactiveParentIds[] = $child->id;
			}
		}

		if (empty($nodeIdsToChangeGlobalActive))
		{
			return;
		}

		try
		{
			NodeTable::updateMulti(
				$nodeIdsToChangeGlobalActive,
				[
					'GLOBAL_ACTIVE' => $active === true ? 'Y' : 'N',
				],
			);
		}
		catch (\Exception)
		{
			throw (new UpdateFailedException())->addError(new Main\Error('Failed to update global active status for child nodes'));
		}
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
	): NodeCollection
	{
		if (empty($departmentIds))
		{
			return new NodeCollection();
		}

		return PublicContainer::getNodeService()->findAllByIds(
			nodeIds: $departmentIds,
			activeFilter: $activeFilter,
		);
	}

	/**
	 * @param Node $node
	 *
	 * @return void
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	private function prepareSort(Node $node): void
	{
		$lastSibling = NodeTable::query()
			->where('PARENT_ID', $node->parentId)
			->setSelect(['SORT'])
			->addOrder('SORT', 'DESC')
			->setLimit(1)
			->fetchObject()
		;

		if ($lastSibling)
		{
			$node->sort = $lastSibling->getSort() + NodeOrderCommand::ORDER_STEP;
		}
	}
}
