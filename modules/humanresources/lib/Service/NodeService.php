<?php

namespace Bitrix\HumanResources\Service;

use Bitrix\HumanResources\Exception\DeleteFailedException;
use Bitrix\HumanResources\Exception\WrongStructureItemException;
use Bitrix\HumanResources\Item\Collection\NodeCollection;
use Bitrix\HumanResources\Item\Node;
use Bitrix\HumanResources\Contract;
use Bitrix\HumanResources\Enum\DepthLevel;
use Bitrix\HumanResources\Enum\Direction;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\DB\SqlQueryException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;

class NodeService implements Contract\Service\NodeService
{
	private Contract\Repository\NodeRepository $nodeRepository;
	private Contract\Service\StructureWalkerService $structureWalkerService;

	public function __construct(
		?Contract\Repository\NodeRepository $nodeRepository = null,
		?Contract\Service\StructureWalkerService $structureWalkerService = null,
	)
	{
		$this->nodeRepository = $nodeRepository ?? Container::getNodeRepository();
		$this->structureWalkerService = $structureWalkerService ?? Container::getStructureWalkerService();
	}
	public function getNodesByUserId(int $userId): NodeCollection
	{
		return $this->nodeRepository->findAllByUserId($userId);
	}

	public function getNodesByUserIdAndUserRoleId(int $userId, int $roleId): NodeCollection
	{
		return $this->nodeRepository->findAllByUserIdAndRoleId($userId, $roleId);
	}

	public function getNodeChildNodes(int $nodeId): NodeCollection
	{
		$node = $this->nodeRepository->getById($nodeId);
		if (!$node)
		{
			return new NodeCollection();
		}

		return $this->nodeRepository->getChildOf($node, DepthLevel::FULL);
	}

	public function getNodeChildNodesByAccessCode(string $accessCode): NodeCollection
	{
		$node = $this->nodeRepository->getByAccessCode($accessCode);
		if (!$node)
		{
			return new NodeCollection();
		}

		return $this->getNodeChildNodes($node->id);
	}

	public function getNodeInformation(int $nodeId): ?Node
	{
		return $this->nodeRepository->getById($nodeId);
	}

	/**
	 * @throws \Bitrix\HumanResources\Exception\CreationFailedException
	 * @throws \Bitrix\Main\SystemException
	 * @throws \Bitrix\Main\ArgumentException
	 */
	public function insertNode(Node $node): Node
	{
		if (!$node->id)
		{
			$this->nodeRepository->create($node);
		}

		return $node;
	}

	/**
	 * @param \Bitrix\HumanResources\Item\Node $node
	 * @param \Bitrix\HumanResources\Item\Node|null $targetNode
	 *
	 * @return \Bitrix\HumanResources\Item\Node
	 */
	public function moveNode(Node $node, ?Node $targetNode = null): Node
	{
		$direction = $targetNode !== null
			? Direction::CHILD
			: Direction::ROOT
		;

		return $this->structureWalkerService->moveNode($direction, $node, $targetNode);
	}

	public function removeNode(Node $node): bool
	{
		try
		{
			$this->structureWalkerService->removeNode($node);
		}
		catch (\Throwable)
		{
			return false;
		}

		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function insertAndMoveNode(Node $node): Node
	{
		$this->insertNode($node);

		$targetNode = null;
		if ($node->parentId)
		{
			$targetNode = $this->nodeRepository->getById($node->parentId);
		}

		return $this->moveNode($node, $targetNode);
	}

	/**
	 * @throws ObjectPropertyException
	 * @throws ArgumentException
	 * @throws SystemException
	 */
	public function updateNode(Node $node): Node
	{
		$nodeEntity = $this->nodeRepository->getById($node->id);

		if (!$nodeEntity)
		{
			return $node;
		}

		if (
			$node->name !== $nodeEntity->name
		)
		{
			$nodeEntity->name = $node->name;
			$nodeEntity = $this->nodeRepository->update($nodeEntity);
		}

		if (
			$node->parentId !== $nodeEntity->parentId
		)
		{
			$targetNode = $this->nodeRepository->getById($node->parentId);
			$nodeEntity = $this->moveNode($nodeEntity, $targetNode);
		}

		return $nodeEntity;
	}
}