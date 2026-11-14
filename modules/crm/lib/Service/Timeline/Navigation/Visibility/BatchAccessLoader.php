<?php

namespace Bitrix\Crm\Service\Timeline\Navigation\Visibility;

use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\Timeline\Context;
use Bitrix\Crm\Service\Timeline\Item;
use Bitrix\Crm\Service\Timeline\Item\Compatible\Model as CompatibleModel;
use Bitrix\Crm\Service\Timeline\Item\LogMessage\Binding\Base as BindingItem;
use Bitrix\Crm\Service\Timeline\Item\Model;
use Bitrix\Crm\V2\Internal\Access\Activity\ActivityAccess;
use Bitrix\Crm\V2\Internal\Entity\Activity\ActivityCommunication;
use Bitrix\Crm\V2\Internal\Repository\Activity\ActivityCommunicationRepository;

class BatchAccessLoader
{
	/**
	 * @param array<int|string, Item|array{ITEM: Item}> $candidates
	 * @return array<int|string, array{
	 *     exists: bool,
	 *     canRead: bool,
	 *     communicationVisible: bool,
	 *     relatedCanRead: bool,
	 *     available: bool
	 * }>
	 */
	public function load(array $candidates, Context $context): array
	{
		$states = [];
		$activityIndexes = [];
		$relatedIndexes = [];
		foreach ($candidates as $index => $candidate)
		{
			$item = $this->itemOf($candidate);
			$model = $this->modelOf($item);
			$associatedTypeId = (int)$model->getAssociatedEntityTypeId();
			$associatedId = (int)$model->getAssociatedEntityId();
			$states[$index] = [
				'exists' => true,
				'canRead' => true,
				'communicationVisible' => true,
				'relatedCanRead' => true,
				'available' => true,
			];

			if ($associatedTypeId === \CCrmOwnerType::Activity)
			{
				$associated = $model->getAssociatedEntityModel()?->toArray() ?? [];
				$exists = $associatedId > 0 && $associated !== [];
				$states[$index]['exists'] = $exists;
				if ($exists)
				{
					$activityIndexes[$associatedId][] = $index;
				}
			}
			elseif ($item instanceof BindingItem)
			{
				if ($associatedTypeId <= 0 || $associatedId <= 0)
				{
					$states[$index]['relatedCanRead'] = false;
				}
				else
				{
					$relatedIndexes[$associatedTypeId][$associatedId][] = $index;
				}
			}
		}

		$activityIds = array_keys($activityIndexes);
		if ($activityIds !== [])
		{
			$readability = $this->activityReadability($context, $activityIds);
			foreach ($activityIds as $activityId)
			{
				foreach ($activityIndexes[$activityId] ?? [] as $index)
				{
					$states[$index]['canRead'] = (bool)($readability[$activityId] ?? false);
				}
			}
		}

		$communications = $this->communications($activityIds);
		$clients = $relatedIndexes;
		foreach ($communications as $activityCommunications)
		{
			foreach ($activityCommunications as $communication)
			{
				$entityTypeId = $communication->getEntityType()->getId();
				$entityId = $communication->getEntityId();
				if ($entityTypeId > 0 && $entityId > 0)
				{
					$clients[$entityTypeId][$entityId] ??= [];
				}
			}
		}
		$readableClients = $this->readableEntities($context, $clients);

		foreach ($relatedIndexes as $entityTypeId => $entityIds)
		{
			foreach ($entityIds as $entityId => $indexes)
			{
				$canRead = isset($readableClients[$entityTypeId][$entityId]);
				foreach ($indexes as $index)
				{
					$states[$index]['relatedCanRead'] = $canRead;
				}
			}
		}

		foreach ($communications as $activityId => $activityCommunications)
		{
			foreach ($activityCommunications as $communication)
			{
				$entityTypeId = $communication->getEntityType()->getId();
				$entityId = $communication->getEntityId();
				if (
					$entityTypeId > 0
					&& $entityId > 0
					&& !isset($readableClients[$entityTypeId][$entityId])
				)
				{
					foreach ($activityIndexes[$activityId] ?? [] as $index)
					{
						$states[$index]['communicationVisible'] = false;
					}
					break;
				}
			}
		}

		return $states;
	}

	/** @param int[] $activityIds @return array<int, bool> */
	protected function activityReadability(Context $context, array $activityIds): array
	{
		$access = $this->activityAccess();
		$access->preload($context->getUserId(), $activityIds);

		$result = [];
		foreach ($activityIds as $activityId)
		{
			$result[$activityId] = $access->canRead($context->getUserId(), $activityId);
		}

		return $result;
	}

	/** @param int[] $activityIds @return array<int, ActivityCommunication[]> */
	protected function communications(array $activityIds): array
	{
		return $activityIds === []
			? []
			: (new ActivityCommunicationRepository())->getByActivityIds($activityIds)
		;
	}

	protected function activityAccess(): ActivityAccess
	{
		return Container::getInstance()->getActivityAccess();
	}

	/**
	 * @param array<int, array<int, mixed>> $entities entity ids by entity type id
	 * @return array<int, array<int, true>>
	 */
	private function readableEntities(Context $context, array $entities): array
	{
		$itemPermissions = $context->getUserPermissions()->item();
		$result = [];
		foreach ($entities as $entityTypeId => $entityIds)
		{
			$ids = array_map('intval', array_keys($entityIds));
			$itemPermissions->preloadPermissionAttributes((int)$entityTypeId, $ids);
			foreach ($ids as $entityId)
			{
				if ($itemPermissions->canRead((int)$entityTypeId, $entityId))
				{
					$result[(int)$entityTypeId][$entityId] = true;
				}
			}
		}

		return $result;
	}

	private function itemOf(Item|array $candidate): Item
	{
		$item = $candidate instanceof Item ? $candidate : ($candidate['ITEM'] ?? null);
		if (!$item instanceof Item)
		{
			throw new \InvalidArgumentException('A visibility candidate must contain a timeline item');
		}

		return $item;
	}

	private function modelOf(Item $item): Model
	{
		$model = $item->getModel();
		if (!$model instanceof CompatibleModel)
		{
			return $model;
		}

		$rawData = $model->getData();

		return Model::createFromArray($rawData)
			->setIsFixed(($rawData['IS_FIXED'] ?? 'N') === 'Y')
		;
	}
}
