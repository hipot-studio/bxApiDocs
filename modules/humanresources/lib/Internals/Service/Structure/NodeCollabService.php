<?php

namespace Bitrix\HumanResources\Internals\Service\Structure;

use Bitrix\HumanResources\Contract\Service\NodeRelationService;
use Bitrix\HumanResources\Exception\WrongStructureItemException;
use Bitrix\HumanResources\Integration\Socialnetwork\CollabService;
use Bitrix\HumanResources\Item;
use Bitrix\HumanResources\Item\Collection\NodeRelationCollection;
use Bitrix\HumanResources\Item\Node;
use Bitrix\HumanResources\Service\Container;
use Bitrix\HumanResources\Type\RelationEntitySubtype;
use Bitrix\HumanResources\Type\RelationEntityType;
use Bitrix\Main\Engine\CurrentUser;

class NodeCollabService
{
	private CollabService $collabService;
	private NodeRelationService $nodeRelationService;

	public function __construct()
	{
		$this->nodeRelationService = Container::getNodeRelationService();
		$this->collabService = Container::getCollabService();
	}

	/**
	 * @param Item\Node $node
	 * @param RelationEntitySubtype $relationEntitySubtype
	 * @return int (chatId)
	 */
	public function create(Item\Node $node, RelationEntitySubtype $relationEntitySubtype): int
	{
		// ToDo: implement

		return 0;
	}

	/**
	 * @param Node $node
	 * @param array $ids
	 * @param bool $checkPermissions
	 * @param int|null $userId
	 * @return NodeRelationCollection
	 * @throws WrongStructureItemException
	 */
	public function bind(Item\Node $node, array $ids, bool $checkPermissions = true, ?int $userId = null): NodeRelationCollection
	{
		$nodeRelationCollection = new NodeRelationCollection();

		if (empty($ids))
		{
			return $nodeRelationCollection;
		}

		if (!$userId && $checkPermissions)
		{
			$userId = (int)CurrentUser::get()->getId();
		}

		if ($checkPermissions)
		{
			$ids = $this->collabService->filterByPermissions($ids, $userId);
		}

		foreach ($ids as $id)
		{
			$nodeRelationCollection->add(
				new Item\NodeRelation(
					nodeId: $node->id,
					entityId: $id,
					entityType: RelationEntityType::COLLAB,
				)
			);
		}

		if (!$nodeRelationCollection->empty())
		{
			return $this->nodeRelationService->linkNodeRelationCollection($nodeRelationCollection) ?? new NodeRelationCollection();
		}

		return $nodeRelationCollection;
	}
}
