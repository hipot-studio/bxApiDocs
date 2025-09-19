<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\Humanresources;

use Bitrix\HumanResources\Enum\DepthLevel;
use Bitrix\HumanResources\Item\Collection\NodeCollection;
use Bitrix\HumanResources\Service\Container;
use Bitrix\Intranet\Dto\EntitySelector\EntitySelectorCodeDto;
use Bitrix\Intranet\Entity\Collection\DepartmentCollection;
use Bitrix\Intranet\Entity\Department;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Loader;
use Bitrix\HumanResources\Item\Node;

class DepartmentRepository
{
	private bool $isAvailable;
	private DepartmentMapper $departmentMapper;

	public function __construct()
	{
		$this->isAvailable = Loader::includeModule('humanresources');
		$this->departmentMapper = new DepartmentMapper();
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
			->findAllByUserId($userId);

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
}
