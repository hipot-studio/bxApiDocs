<?php

declare(strict_types=1);

namespace Bitrix\HumanResources\Internals\Repository\Structure;

use Bitrix\HumanResources\Builder\Structure\Filter\Column\EntityIdFilter;
use Bitrix\HumanResources\Builder\Structure\Filter\Column\IdFilter;
use Bitrix\HumanResources\Builder\Structure\Filter\Column\Node\NodeNameFilter;
use Bitrix\HumanResources\Builder\Structure\Filter\Column\Node\NodeTypeFilter;
use Bitrix\HumanResources\Builder\Structure\Filter\NodeFilter;
use Bitrix\HumanResources\Builder\Structure\Filter\NodeMemberFilter;
use Bitrix\HumanResources\Builder\Structure\Filter\SelectionCondition\Node\NodeAccessFilter;
use Bitrix\HumanResources\Builder\Structure\NodeDataBuilder;
use Bitrix\HumanResources\Builder\Structure\NodeMemberDataBuilder;
use Bitrix\HumanResources\Builder\Structure\Sort\NodeSort;
use Bitrix\HumanResources\Contract;
use Bitrix\HumanResources\Enum\DepthLevel;
use Bitrix\HumanResources\Enum\Direction;
use Bitrix\HumanResources\Enum\NodeActiveFilter;
use Bitrix\HumanResources\Enum\SortDirection;
use Bitrix\HumanResources\Internals\Repository\Mapper\NodeMapper;
use Bitrix\HumanResources\Item\Collection\NodeCollection;
use Bitrix\HumanResources\Item\Node;
use Bitrix\HumanResources\Model\NodeTable;
use Bitrix\HumanResources\Service\Container;
use Bitrix\HumanResources\Type\AccessCodeType;
use Bitrix\HumanResources\Type\MemberEntityType;
use Bitrix\HumanResources\Type\NodeEntityType;
use Bitrix\HumanResources\Item;
use Bitrix\HumanResources\Type\StructureAction;
use Bitrix\HumanResources\Util\AccessCodeHelper;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\SystemException;

final class NodeRepository
{
	private const NODE_ENTITY_CACHE_KEY = 'structure/node/entity/%d';

	private Contract\Util\CacheManager $cacheManager;
	private NodeMapper $mapper;

	public function __construct()
	{
		$this->cacheManager = Container::getCacheManager();
		$this->cacheManager->setTtl(86400*7);
		$this->mapper = new NodeMapper();
	}

	/**
	 * @param int $structureId
	 *
	 * @return array<array-key, int> A map of structure nodes where the key is the node ID and the value is the parent node ID or null
	 */
	public function getStructuresNodeMap(int $structureId): array
	{
		$map = [];

		$nodeCollection =
			(new NodeDataBuilder())
				->setSelect(['ID', 'PARENT_ID', 'TYPE'])
				->addFilter(
					new NodeFilter(
						entityTypeFilter: NodeTypeFilter::fromNodeTypes([NodeEntityType::DEPARTMENT, NodeEntityType::TEAM]),
						structureId: $structureId,
						depthLevel: DepthLevel::FULL,
					),
				)
				->getAll()
		;

		foreach ($nodeCollection as $node)
		{
			$map[$node->id] = [
				'parentId' => (int)$node->parentId,
				'entityType' => $node->type ?? '',
			];
		}

		return $map;
	}

	/**
	 * @param int $nodeId
	 * @param StructureAction|null $structureAction
	 *
	 * @return Node|null
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function getById(
		int $nodeId,
		?StructureAction $structureAction = null,
	): ?Node
	{
		$nodeCacheKey = sprintf(self::NODE_ENTITY_CACHE_KEY, $nodeId);
		$nodeCache = $this->cacheManager->getData($nodeCacheKey);

		if ($nodeCache)
		{
			$nodeCache['type'] = NodeEntityType::tryFrom($nodeCache['type']);
			$nodeCache['createdAt'] = null;
			$nodeCache['updatedAt'] = null;

			$node = new Node(...$nodeCache);
		}
		else
		{
			$query = NodeTable::query()
				->setSelect(['*', 'ACCESS_CODE',])
				->where('ID', $nodeId)
				->setLimit(1)
			;

			$nodeObject = $query->fetchObject();
			$node = $nodeObject !== null ? $this->mapper->convertFromModel($nodeObject) : null;
			if ($node)
			{
				$this->cacheManager->setData($nodeCacheKey, $node);
			}
		}

		if (!$node || !$structureAction)
		{
			return $node;
		}

		return
			(new NodeDataBuilder())
				->addFilter(
					new NodeFilter(
						idFilter: IdFilter::fromId($node->id),
						entityTypeFilter: NodeTypeFilter::fromNodeType($node->type),
						structureId: $node->structureId,
						accessFilter: new NodeAccessFilter($structureAction)
					),
				)
				->setSort(new NodeSort(sort: SortDirection::Asc))
				->get()
		;
	}

	/**
	 * @param int $nodeId
	 *
	 * @return Node|null
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function getByIdWithDepth(int $nodeId): ?Node
	{
		$query = NodeTable::query()
			->setSelect(['*', 'ACCESS_CODE', 'CHILD_NODES'])
			->where('ID', $nodeId)
			->where('PARENT_NODES.CHILD_ID', $nodeId)
			->addOrder('CHILD_NODES.DEPTH', 'DESC')
			->setLimit(1)
			->setCacheTtl(86400)
			->cacheJoins(true)
		;

		$node = $query->fetchObject();

		return $node !== null ? $this->mapper->convertFromModel($node) : null;
	}

	/**
	 * @param string $accessCode
	 *
	 * @return Node|null
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function getByAccessCode(string $accessCode): ?Node
	{
		static $nodes = [];
		if (isset($nodes[$accessCode]))
		{
			return $nodes[$accessCode];
		}

		$nodeByAccessCode = $this->extractIdByAccessCodeAndFind($accessCode);
		if ($nodeByAccessCode)
		{
			$nodes[$accessCode] = $nodeByAccessCode;

			return $nodeByAccessCode;
		}

		$accessCode = str_replace('DR', 'D', $accessCode);

		$node = NodeTable::query()
			->setSelect(['*'])
			->addSelect('ACCESS_CODE')
			->where('ACCESS_CODE.ACCESS_CODE', $accessCode)
			->setLimit(1)
			->setCacheTtl(86400)
			->cacheJoins(true)
			->exec()
			->fetchObject()
		;

		$nodes[$accessCode] = !$node ? null : $this->mapper->convertFromModel($node);

		return $nodes[$accessCode];
	}

	public function getRootNodeByStructureId(int $structureId): ?Node
	{
		$node = NodeTable::query()
			->setSelect(['*'])
			->addSelect('ACCESS_CODE')
			->where('STRUCTURE_ID', $structureId)
			->where('PARENT_ID', 0)
			->setCacheTtl(86400)
			->cacheJoins(true)
			->fetchObject()
		;

		return $node !== null ? $this->mapper->convertFromModel($node) : null;
	}

	public function findAll(
		array $nodeTypes = [NodeEntityType::DEPARTMENT],
		?int $structureId = null,
		?StructureAction $structureAction = null,
		NodeActiveFilter $activeFilter = NodeActiveFilter::ONLY_GLOBAL_ACTIVE,
		int $limit = 0,
		int $offset = 0,
	): Item\Collection\NodeCollection
	{
		$accessFilter = $structureAction ? new NodeAccessFilter($structureAction) : null;

		return
			(new NodeDataBuilder())
				->addFilter(
					new NodeFilter(
						entityTypeFilter: NodeTypeFilter::fromNodeTypes($nodeTypes),
						structureId: $structureId,
						active: $activeFilter,
						accessFilter: $accessFilter,
					),
				)
				->setSort(new NodeSort(sort: SortDirection::Asc))
				->setLimit($limit)
				->setOffset($offset)
				->getAll()
		;
	}

	public function findAllByIds(
		array $nodeIds,
		?int $structureId = null,
		array $nodeTypes = [NodeEntityType::DEPARTMENT],
		?StructureAction $structureAction = null,
		NodeActiveFilter $activeFilter = NodeActiveFilter::ONLY_GLOBAL_ACTIVE,
	): NodeCollection
	{
		$accessFilter = $structureAction ? new NodeAccessFilter($structureAction) : null;

		return
			(new NodeDataBuilder())
				->addFilter(
					new NodeFilter(
						idFilter: IdFilter::fromIds($nodeIds),
						entityTypeFilter: NodeTypeFilter::fromNodeTypes($nodeTypes),
						structureId: $structureId,
						active: $activeFilter,
						accessFilter: $accessFilter,
					),
				)
				->setSort(new NodeSort(sort: SortDirection::Asc))
				->getAll()
		;
	}

	public function findChildrenByNodeIds(
		array $nodeIds,
		?int $structureId = null,
		DepthLevel|int $depthLevel = DepthLevel::FIRST,
		array $nodeTypes = [NodeEntityType::DEPARTMENT, NodeEntityType::TEAM],
		?StructureAction $structureAction = null,
		NodeActiveFilter $activeFilter = NodeActiveFilter::ONLY_GLOBAL_ACTIVE,
	): Item\Collection\NodeCollection
	{
		if (empty($nodeIds))
		{
			return new NodeCollection();
		}

		$accessFilter = $structureAction ? new NodeAccessFilter($structureAction) : null;

		return (new NodeDataBuilder())
			->addFilter(
				new NodeFilter(
					idFilter: IdFilter::fromIds($nodeIds),
					entityTypeFilter: NodeTypeFilter::fromNodeTypes($nodeTypes),
					structureId: $structureId,
					depthLevel: $depthLevel,
					active: $activeFilter,
					accessFilter: $accessFilter,
				),
			)
			->setSort(new NodeSort(sort: SortDirection::Asc))
			->getAll()
		;
	}

	public function findParentsOfNode(
		int $nodeId,
		array $nodeTypes,
		DepthLevel|int $depthLevel = DepthLevel::FIRST,
		?StructureAction $structureAction = null,
	): ?Item\Collection\NodeCollection
	{
		$accessFilter = $structureAction ? new NodeAccessFilter($structureAction) : null;
		$node = $this->getById($nodeId);
		if (!$node)
		{
			return null;
		}

		return (new NodeDataBuilder())
			->addFilter(
				new NodeFilter(
					idFilter: IdFilter::fromId($nodeId),
					entityTypeFilter: NodeTypeFilter::fromNodeTypes($nodeTypes),
					structureId: $node->structureId,
					direction: Direction::ROOT,
					depthLevel: $depthLevel,
					accessFilter: $accessFilter,
				),
			)
			->setSort(new NodeSort(depth: SortDirection::Desc))
			->getAll()
		;
	}

	/**
	 * @param int $entityId
	 * @param MemberEntityType $memberEntityType
	 * @param NodeEntityType[] $nodeTypes
	 * @param StructureAction|null $structureAction
	 * @param NodeActiveFilter $activeFilter
	 *
	 * @return NodeCollection
	 */
	public function findAllByMemberEntityId(
		int $entityId,
		MemberEntityType $memberEntityType = MemberEntityType::USER,
		?int $structureId = null,
		array $nodeTypes = [NodeEntityType::DEPARTMENT],
		?StructureAction $structureAction = null,
		NodeActiveFilter $activeFilter = NodeActiveFilter::ONLY_GLOBAL_ACTIVE,
	): Item\Collection\NodeCollection
	{
		if (
			!empty($nodeTypes)
			|| $activeFilter !== NodeActiveFilter::ONLY_GLOBAL_ACTIVE
			|| $structureAction
		)
		{
			$nodeFilter = new NodeFilter(
				entityTypeFilter: NodeTypeFilter::fromNodeTypes($nodeTypes),
				structureId: $structureId,
				active: $activeFilter,
				accessFilter: $structureAction ? new NodeAccessFilter($structureAction) : null,
			);
		}

		$nodeMemberCollection
			= (new NodeMemberDataBuilder())
				->addFilter(
					new NodeMemberFilter(
						entityIdFilter: EntityIdFilter::fromEntityId($entityId),
						entityType: $memberEntityType,
						nodeFilter: $nodeFilter ?? null,
						active: null,
					),
				)
				->getAll()
		;

		$nodeIds = $nodeMemberCollection->getNodeIds();
		if (empty($nodeIds))
		{
			return new NodeCollection();
		}

		return
			(new NodeDataBuilder())
				->addFilter(
					new NodeFilter(
						idFilter: IdFilter::fromIds($nodeIds),
						entityTypeFilter: NodeTypeFilter::fromNodeTypes($nodeTypes),
						structureId: $structureId,
						active: $activeFilter,
						accessFilter: $structureAction ? new NodeAccessFilter($structureAction) : null,
					),
				)
				->getAll()
		;
	}

	public function findAllByName(
		?string $name,
		bool $strict = false,
		?array $parentIds = null,
		array $nodeTypes = [NodeEntityType::DEPARTMENT],
		?int $structureId = null,
		DepthLevel|int $depthLevel = DepthLevel::FULL,
		?StructureAction $structureAction = null,
		NodeActiveFilter $activeFilter = NodeActiveFilter::ONLY_GLOBAL_ACTIVE,
		?int $limit = 100,
	): NodeCollection
	{
		$accessFilter = $structureAction ? new NodeAccessFilter($structureAction) : null;
		$idFilter = empty($parentIds) ? null : IdFilter::fromIds($parentIds);
		$nameFilter = new NodeNameFilter($name, $strict);

		return NodeDataBuilder::createWithFilter(
			new NodeFilter(
				idFilter: $idFilter,
				entityTypeFilter: NodeTypeFilter::fromNodeTypes($nodeTypes),
				structureId:  $structureId,
				direction: Direction::CHILD,
				depthLevel: $depthLevel,
				active: $activeFilter,
				accessFilter: $accessFilter,
				name:$nameFilter,
			)
		)
			->setSort(new NodeSort(depth: SortDirection::Asc, type: SortDirection::Asc))
			->setLimit($limit)
			->getAll()
		;
	}

	public function findAllByXmlId(
		string $xmlId,
		int $structureId,
		NodeActiveFilter $activeFilter = NodeActiveFilter::ONLY_GLOBAL_ACTIVE
	): NodeCollection
	{
		$query = NodeTable::query()
			->setSelect(['*', 'ACCESS_CODE', 'CHILD_NODES'])
			->where('STRUCTURE_ID', $structureId)
			->where('XML_ID', $xmlId)
		;

		$query = $this->setNodeActiveFilter($query, $activeFilter);
		$nodeModelArray = $query->fetchAll();

		return !$nodeModelArray
			? new NodeCollection()
			: $this->mapper->convertFromOrmArrayToNodeCollection($nodeModelArray)
		;
	}


	private function extractIdByAccessCodeAndFind(string $accessCode): ?Node
	{
		foreach (AccessCodeType::getTeamTypes() as $type)
		{
			if (!str_starts_with($accessCode, $type->value))
			{
				continue;
			}

			$id = AccessCodeHelper::extractIdFromCode($accessCode, $type);
			if (!$id)
			{
				continue;
			}

			$node = $this->getById($id);
			if (!$node?->isTeam())
			{
				return null;
			}

			return $node;
		}

		foreach (AccessCodeType::getDepartmentTypes() as $type)
		{
			if (!str_starts_with($accessCode, $type->value))
			{
				continue;
			}

			$id = (int)AccessCodeHelper::extractIdFromCode($accessCode, $type);
			if (!$id)
			{
				continue;
			}

			$node = $this->getById($id);
			if (!$node?->isDepartment())
			{
				return null;
			}

			return $node;
		}

		$id = AccessCodeHelper::extractIdFromCode($accessCode);
		if ($id)
		{
			return $this->getById($id);
		}

		return null;
	}

	private function setNodeActiveFilter(Query $query, NodeActiveFilter $activeFilter): Query
	{
		return match ($activeFilter)
		{
			NodeActiveFilter::ONLY_ACTIVE => $query->where('ACTIVE', true),
			NodeActiveFilter::ONLY_GLOBAL_ACTIVE => $query->where('GLOBAL_ACTIVE', true),
			default => $query,
		};
	}
}
