<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\Humanresources;

use Bitrix\HumanResources\Enum\DepthLevel;
use Bitrix\HumanResources\Item\Collection\NodeCollection;
use Bitrix\HumanResources\Item\NodeMember;
use Bitrix\HumanResources\Public\Service\Container as PublicContainer;
use Bitrix\HumanResources\Service\Container;
use Bitrix\Intranet\Dto\EntitySelector\EntitySelectorCodeDto;
use Bitrix\Intranet\Entity\Collection\DepartmentCollection;
use Bitrix\Intranet\Entity\Collection\UserCollection;
use Bitrix\Intranet\Entity\Department;
use Bitrix\Intranet\Service\ServiceContainer;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Loader;
use Bitrix\HumanResources\Item\Node;
use Bitrix\HumanResources\Builder\Structure\Filter\Column\Node\NodeTypeFilter;
use Bitrix\HumanResources\Builder\Structure\Filter\NodeFilter;
use Bitrix\HumanResources\Builder\Structure\Filter\SelectionCondition\Node\NodeAccessFilter;
use Bitrix\HumanResources\Builder\Structure\Sort\NodeSort;
use Bitrix\HumanResources\Builder\Structure\NodeDataBuilder;
use Bitrix\HumanResources\Type\NodeEntityType;
use Bitrix\HumanResources\Type\StructureAction;
use Bitrix\HumanResources\Enum\SortDirection;

class DepartmentRepository
{
	private bool $isAvailable;
	private DepartmentMapper $departmentMapper;

	public function __construct()
	{
		$this->isAvailable = Loader::includeModule('humanresources');
		$this->departmentMapper = new DepartmentMapper();
	}

	public function getDepartmentHeadsByDepartmentCollection(DepartmentCollection $departmentCollection): UserCollection
	{
		if (!$this->isAvailable)
		{
			return new UserCollection();
		}

		$headRoleId = Container::getRoleRepository()
			->findByXmlId(NodeMember::DEFAULT_ROLE_XML_ID['HEAD'])
			?->id
		;

		$nodeCollection = $this->createNodeCollectionFromDepartmentCollection($departmentCollection);

		$nodeMemberCollection = Container::getNodeMemberRepository()
			->findAllByRoleIdAndNodeCollection($headRoleId, $nodeCollection)
		;

		$userIds = $nodeMemberCollection->getEntityIds();

		return ServiceContainer::getInstance()->userRepository()->findUsersByIds($userIds);
	}

	public function getDepartmentHeadsByUserId(int $userId): UserCollection
	{
		$nodeMemberCollection = PublicContainer::getUserDepartmentService()
			->getUserHeads($userId)
		;

		$userIds = $nodeMemberCollection->getEntityIds();

		return ServiceContainer::getInstance()->userRepository()->findUsersByIds($userIds);
	}

	public function getDepartmentsByEntitySelectorAccessCode(EntitySelectorCodeDto $accessCode): DepartmentCollection
	{
		if (!$this->isAvailable)
		{
			return new DepartmentCollection();
		}

		$flatDepartmentCodes = array_map(fn (int $departmentId) => 'D' . $departmentId, $accessCode->departmentIds);
		$departmentWithAllChildCodes = array_map(fn (int $departmentId) => 'D' . $departmentId, $accessCode->departmentWithAllChildIds);

		$nodeRepository = Container::getNodeRepository();

		$flatNodes = $nodeRepository->findAllByAccessCodes($flatDepartmentCodes);

		$nodesWithChild = $nodeRepository->findAllByAccessCodes($departmentWithAllChildCodes);
		$nodesWithChild = $nodeRepository->getChildOfNodeCollection(
			$nodesWithChild,
			DepthLevel::FULL,
		);

		$allNodes = $flatNodes->merge($nodesWithChild);

		return $this->createDepartmentCollectionFromNodeCollection($allNodes);
	}

	public function getDepartmentsByUserId(int $userId): DepartmentCollection
	{
		$nodeCollection = Container::getNodeRepository()
			->findAllByUserId($userId)
		;

		return $this->createDepartmentCollectionFromNodeCollection($nodeCollection);
	}

	/**
	 * @throws ArgumentException
	 */
	public function createDepartmentCollectionFromNodeCollection(NodeCollection $nodeCollection): DepartmentCollection
	{
		$collection = new DepartmentCollection();
		foreach ($nodeCollection as $node)
		{
			$collection->add($this->departmentMapper->createDepartmentFromNode($node));
		}

		return $collection;
	}

	public function createDepartmentFromNode(Node $node): Department
	{
		return $this->departmentMapper->createDepartmentFromNode($node);
	}

	public function searchAvailableDepartmentsByName(
		int $userId,
		?string $name = null,
		int $limit = 100,
		int $offset = 0,
	): DepartmentCollection
	{
		if (!$this->isAvailable || $userId <= 0 || $limit < 1 || $offset < 0)
		{
			return new DepartmentCollection();
		}

		$nodeCollection = $this->buildAvailableDepartmentsByNameBuilder($userId, $name)
			->setLimit($limit)
			->setOffset($offset)
			->getAll()
		;

		return $this->createDepartmentCollectionFromNodeCollection($nodeCollection);
	}

	public function countAvailableDepartmentsByName(
		int $userId,
		?string $name = null,
	): int
	{
		if (!$this->isAvailable || $userId <= 0)
		{
			return 0;
		}

		try
		{
			return (int)$this->buildAvailableDepartmentsByNameBuilder($userId, $name)
				->prepareQuery()
				->queryCountTotal()
				;
		}
		catch (\Throwable)
		{
			return 0;
		}
	}

	private function buildAvailableDepartmentsByNameBuilder(
		int $userId,
		?string $name = null,
	): NodeDataBuilder
	{
		$name = trim((string)$name);
		$name = $name === '' ? null : $name;

		return NodeDataBuilder::createWithFilter(
			new NodeFilter(
				entityTypeFilter: NodeTypeFilter::fromNodeType(NodeEntityType::DEPARTMENT),
				accessFilter: new NodeAccessFilter(StructureAction::InviteUserAction, $userId),
				name: $name,
			),
		)
			->setSort(new NodeSort(depth: SortDirection::Asc, sort: SortDirection::Asc))
			;
	}

	private function createNodeCollectionFromDepartmentCollection(DepartmentCollection $departmentCollection): NodeCollection
	{
		$collection = new NodeCollection();

		foreach ($departmentCollection as $department)
		{
			$collection->add($this->departmentMapper->createNodeFromDepartment($department));
		}

		return $collection;
	}
}
