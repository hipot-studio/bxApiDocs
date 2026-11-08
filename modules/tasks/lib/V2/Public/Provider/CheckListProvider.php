<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Provider;

use Bitrix\Tasks\CheckList\CheckListFacade;
use Bitrix\Tasks\CheckList\Node\Nodes;
use Bitrix\Tasks\CheckList\Task\TaskCheckListFacade;
use Bitrix\Tasks\CheckList\Template\TemplateCheckListFacade;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Repository\CheckListUserOptionRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\Mapper\CheckListMapper;
use Bitrix\Tasks\V2\Internal\Service\CheckList\CheckListTreeService;

class CheckListProvider
{
	public function __construct(
		private readonly CheckListMapper $checkListMapper,
		private readonly CheckListTreeService $treeService,
		private readonly CheckListUserOptionRepositoryInterface $checkListUserOptionRepository,
	)
	{
	}

	/**
	 * @param array<int, string> $nodeIdByServerId
	 */
	public function getByEntity(
		int $entityId,
		int $userId,
		Entity\CheckList\Type $type,
		array $nodeIdByServerId = [],
	): Entity\CheckList
	{
		$dataClass = $this->resolveDataClass($type);

		$items = $dataClass::getByEntityId($entityId);

		$items = $dataClass::fillActionsForItems($entityId, $userId, $items);

		$items = $this->fillVisibilityStateForItems($userId, $items);

		if (!empty($nodeIdByServerId))
		{
			$items = $this->injectNodeIds($items, $nodeIdByServerId);
		}

		$items = $this->treeService->buildTree($items);

		return $this->checkListMapper->mapToEntity($items);
	}

	public function getByEntityWithoutUserState(int $entityId, Entity\CheckList\Type $type): Entity\CheckList
	{
		$dataClass = $this->resolveDataClass($type);

		$items = $dataClass::getByEntityId($entityId);

		$items = $this->treeService->buildTree($items);

		return $this->checkListMapper->mapToEntity($items);
	}

	public function merge(int $entityId, int $userId, array $checkLists, Entity\CheckList\Type $type): ?Nodes
	{
		$dataClass = $this->resolveDataClass($type);

		$result = $dataClass::merge($entityId, $userId, $checkLists);
		if (
			!$result
			|| !$result->isSuccess()
		)
		{
			return null;
		}

		$traversedItems = $result->getData()['TRAVERSED_ITEMS'] ?? [];

		return Nodes::createFromArray($traversedItems);
	}

	/**
	 * @return class-string<CheckListFacade>
	 */
	private function resolveDataClass(Entity\CheckList\Type $type): string
	{
		// todo: provider
		return match ($type)
		{
			Entity\CheckList\Type::Task => TaskCheckListFacade::class,
			Entity\CheckList\Type::Template => TemplateCheckListFacade::class,
		};
	}

	/**
	 * @param array<int, string> $nodeIdByServerId
	 */
	private function injectNodeIds(array $items, array $nodeIdByServerId): array
	{
		return array_map(static function (array $item) use ($nodeIdByServerId): array
		{
			$serverId = (int)($item['ID'] ?? 0);
			if (isset($nodeIdByServerId[$serverId]))
			{
				$item['NODE_ID'] = $nodeIdByServerId[$serverId];
			}

			return $item;
		}, $items);
	}

	private function fillVisibilityStateForItems(int $currentUserId, array $items): array
	{
		if (empty($items))
		{
			return $items;
		}

		$itemIds = array_column($items, 'ID');
		$itemIds = array_map('intval', $itemIds);

		$options = $this->checkListUserOptionRepository->isSet(
			$currentUserId,
			$itemIds,
			[
				Entity\CheckList\Option::COLLAPSED,
				Entity\CheckList\Option::EXPANDED,
			],
		);

		return array_map(
			function($item) use ($options)
			{
				$itemId = (int)$item['ID'];

				$item['COLLAPSED'] = isset($options[$itemId][Entity\CheckList\Option::COLLAPSED]);
				$item['EXPANDED'] = isset($options[$itemId][Entity\CheckList\Option::EXPANDED]);

				return $item;
			},
			$items,
		);
	}
}
