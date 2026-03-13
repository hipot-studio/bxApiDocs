<?php

namespace Bitrix\HumanResources\Internals\Service\Structure;

use Bitrix\HumanResources\Exception\UpdateFailedException;
use Bitrix\HumanResources\Exception\WrongStructureItemException;
use Bitrix\HumanResources\Internals\Service\Container as InternalContainer;
use Bitrix\HumanResources\Item;
use Bitrix\HumanResources\Item\Collection\NodeMemberCollection;
use Bitrix\HumanResources\Item\NodeMember;
use Bitrix\HumanResources\Repository\NodeMemberRepository;
use Bitrix\HumanResources\Service\Container;
use Bitrix\HumanResources\Type\MemberEntityType;
use Bitrix\HumanResources\Type\NodeEntityType;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\DB\SqlQueryException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;

class NodeMemberService
{
	private const NODE_MEMBER_HEADS_CACHE_KEY = 'node_member_heads_%s_%s_%d';
	private NodeMemberRepository $nodeMemberRepository;

	public function __construct()
	{
		$this->nodeMemberRepository = Container::getNodeMemberRepository();
	}

	/**
	 * @param Item\NodeMember $nodeMember
	 * @param Item\Node $node
	 * @param Item\Role|null $role
	 *
	 * @return Item\NodeMember
	 * @throws UpdateFailedException
	 * @throws ArgumentException
	 * @throws SqlQueryException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function moveMember(Item\NodeMember $nodeMember, Item\Node $node, ?Item\Role $role = null): Item\NodeMember
	{
		$nodeMember = $this->nodeMemberRepository->findById((int)$nodeMember->id);
		if (!$nodeMember)
		{
			throw (new UpdateFailedException(
				'Node member not found',
			));
		}

		if ($nodeMember->node?->type !== $node->type)
		{
			throw (new UpdateFailedException(
				'Wrong target node type',
			));
		}

		if ($role)
		{
			if (!$this->isRoleCorrectForNode($node, $role))
			{
				throw (new UpdateFailedException(
					'Wrong role for target node type',
				));
			}

			$nodeMember->role = $role->id;
		}

		$nodeMember->nodeId = $node->id;
		$this->nodeMemberRepository->update($nodeMember);

		return $nodeMember;
	}

	public function isUserInMultipleNodes(int $userId, NodeEntityType $nodeEntityType = NodeEntityType::DEPARTMENT): bool
	{
		$nodeMemberCollection = $this->nodeMemberRepository->findAllByEntityIdAndEntityTypeAndNodeType(
			entityId: $userId,
			entityType: MemberEntityType::USER,
			nodeType: NodeEntityType::DEPARTMENT,
			limit: 2,
		);

		return $nodeMemberCollection->count() > 1;
	}

	/**
	 * Returns the nearest heads from the branch where the given entity belongs
	 *
	 * @param int $entityId
	 * @param MemberEntityType $memberEntityType
	 * @param NodeEntityType $nodeEntityType
	 *
	 * @return NodeMemberCollection
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 * @throws WrongStructureItemException
	 */
	public function getNodeMemberHeads(
		int $entityId,
		MemberEntityType $memberEntityType = MemberEntityType::USER,
		NodeEntityType $nodeEntityType = NodeEntityType::DEPARTMENT,
	): NodeMemberCollection
	{
		$cacheKey = sprintf(
			self::NODE_MEMBER_HEADS_CACHE_KEY,
			$nodeEntityType->value,
			$memberEntityType->value,
			$entityId
		);
		$cacheDir = NodeMemberRepository::NODE_MEMBER_CACHE_DIR;
		$cacheData = Container::getCacheManager()->getData($cacheKey, $cacheDir);

		if (isset($cacheData['heads']))
		{
			return NodeMemberCollection::wakeUp($cacheData['heads']);
		}

		$headsCollection = new NodeMemberCollection();

		$headRole = InternalContainer::getRoleService()->getHeadRoleByNodeType($nodeEntityType);
		if (!$headRole)
		{
			return $headsCollection;
		}

		$nodeMemberCollection = InternalContainer::getNodeMemberRepository()->findAllByEntityIds(
			entityIds: [$entityId],
			memberEntityType: $memberEntityType,
			nodeTypes: [$nodeEntityType],
		);

		if ($nodeMemberCollection->empty())
		{
			return $headsCollection;
		}

		foreach ($nodeMemberCollection as $nodeMember)
		{
			$node = $nodeMember->node;
			if (!$node)
			{
				continue;
			}

			if ((int)$nodeMember->roles[0] === $headRole->id)
			{
				$node = Container::getNodeRepository()->getById($node->parentId);
				if (!$node || $node->type !== $nodeEntityType)
				{
					continue;
				}
			}

			$nodeHeadsCollection = new NodeMemberCollection();
			while ($nodeHeadsCollection->empty() && $node)
			{
				$nodeHeadsCollection = InternalContainer::getNodeMemberRepository()
					->findAllByRoleIdAndNodeId($headRole->id, $node->id)
				;

				if (!$nodeHeadsCollection->empty() || !$node->parentId)
				{
					foreach ($nodeHeadsCollection as $headMember)
					{
						if ($headMember->entityId !== $entityId)
						{
							$headsCollection->add($headMember);
						}
					}

					break;
				}

				$node = Container::getNodeRepository()->getById($node->parentId);
			}
		}

		Container::getCacheManager()->setData($cacheKey, ['heads' => $headsCollection->getValues()], $cacheDir);

		return $headsCollection;
	}

	private function isRoleCorrectForNode(Item\Node $node, Item\Role $role): bool
	{
		if ($node->type === NodeEntityType::DEPARTMENT)
		{
			return in_array($role->xmlId, NodeMember::DEFAULT_ROLE_XML_ID, true);
		}

		if ($node->type === NodeEntityType::TEAM)
		{
			return in_array($role->xmlId, NodeMember::TEAM_ROLE_XML_ID, true);
		}

		return false;
	}
}