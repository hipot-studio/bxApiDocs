<?php

declare(strict_types=1);

namespace Bitrix\HumanResources\Rest\Trait;

use Bitrix\HumanResources\Access\Model\NodeModel;
use Bitrix\HumanResources\Access\StructureAccessController;
use Bitrix\HumanResources\Access\StructureActionDictionary;
use Bitrix\HumanResources\Item\Node as NodeItem;
use Bitrix\HumanResources\Service\Container;
use Bitrix\HumanResources\Type\NodeEntityType;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Rest\V3\Exception\AccessDeniedException;
use Bitrix\Rest\V3\Exception\EntityNotFoundException;

trait NodeControllerTrait
{
	private StructureAccessController $accessController;
	private int $userId;

	protected function initNodeContext(): void
	{
		$this->userId = (int)CurrentUser::get()->getId();
		$this->accessController = new StructureAccessController($this->userId);
	}

	private function requireNodeById(int $nodeId): NodeItem
	{
		$node = Container::getNodeRepository()->getById($nodeId);
		if ($node === null)
		{
			throw new EntityNotFoundException($nodeId);
		}

		return $node;
	}

	private function checkNodeViewAccess(NodeItem $node): void
	{
		$actionId = $node->type === NodeEntityType::TEAM
			? StructureActionDictionary::ACTION_TEAM_VIEW
			: StructureActionDictionary::ACTION_STRUCTURE_VIEW
		;

		if (!$this->accessController->check($actionId, NodeModel::createFromNode($node)))
		{
			throw new AccessDeniedException();
		}
	}
}
