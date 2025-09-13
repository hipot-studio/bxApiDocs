<?php

namespace Bitrix\HumanResources\Access\Model;

use Bitrix\HumanResources\Config\Feature;
use Bitrix\HumanResources\Controller\Structure\Node;
use Bitrix\HumanResources\Service\Container;
use Bitrix\HumanResources\Type\NodeEntityType;
use Bitrix\Main\Access\AccessibleItem;
use Bitrix\HumanResources\Item;

final class NodeModel implements AccessibleItem
{
	private ?Item\Node $node = null;
	private ?int $targetNodeId = null;

	public static function createFromId(?int $itemId): NodeModel
	{
		$model = new self();

		if ($itemId !== null)
		{
			$nodeRepository = Container::getNodeRepository();
			if (Feature::instance()->isCrossFunctionalTeamsAvailable())
			{
				$nodeRepository->setSelectableNodeEntityTypes([
					NodeEntityType::DEPARTMENT,
					NodeEntityType::TEAM,
				]);
			}

			$model->node = $nodeRepository->getById($itemId);
		}

		return $model;
	}

	public function getId(): int
	{
		return $this->node?->id ?? 0;
	}

	public function getNode(): ?Item\Node
	{
		return $this->node;
	}

	public function getParentId(): ?int
	{
		return $this->node?->parentId ?? null;
	}

	public function getTargetId(): ?int
	{
		return $this->targetNodeId;
	}

	public function setTargetNodeId(int $itemId): void
	{
		$nodeRepository = Container::getNodeRepository();
		if (Feature::instance()->isCrossFunctionalTeamsAvailable())
		{
			$nodeRepository->setSelectableNodeEntityTypes([
				NodeEntityType::DEPARTMENT,
			 	NodeEntityType::TEAM,
			]);
		}

		$this->targetNodeId =  $nodeRepository->getById($itemId)?->id ?? null;
	}
}