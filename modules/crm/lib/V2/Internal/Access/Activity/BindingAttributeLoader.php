<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Access\Activity;

use Bitrix\Crm\Service\Container;
use Bitrix\Crm\V2\Internal\Entity\Activity\ActivityBinding;
use Bitrix\Crm\V2\Internal\Repository\Activity\ActivityBindingRepository;
use Bitrix\Crm\V2\Internal\Repository\Activity\ActivityRepository;
use Bitrix\Crm\V2\Internal\Repository\Activity\ActivityRepositoryInterface;

/**
 * What the rights over a set of activities are read from: the objects each one is bound to, and the
 * permission attributes of those objects.
 *
 * BATCH FIRST, AND THAT IS THE WHOLE POINT. Whoever asks about many activities asks once, before the checks:
 * one query for the bindings of all of them, one for the owner columns of those that have no binding left,
 * and one call per kind of object to warm its permission attributes. What follows is a loop of checks that
 * reaches the database for neither - the attributes are already in the permission machinery and the bindings
 * are already here. The same shape as the batch of the Item provider, which warms the attributes of a page of
 * items before deciding on each of them.
 *
 * ASKING WITHOUT PRELOADING IS ALLOWED and costs what it costs: an activity nobody preloaded is read on
 * demand, once, and remembered like a preloaded one. This is not an escape from the batch - a reader that
 * knows its set is expected to hand it over - but a single check must not be forced to pretend it is a batch.
 *
 * HOW LONG WHAT IS READ LIVES: as long as this object, which is at most the hit. A change to the bindings of
 * an activity inside the hit is what {@see self::forget()} is for, and it is called from the same place the
 * decisions are forgotten from.
 *
 * WHAT IS NOT PRELOADED HERE. The rows of the activities themselves: the responsible person and the model
 * the provider of the activity is asked with are read through the repository, one activity at a time. That is
 * the remaining per-activity read of a batch, and closing it belongs to whoever gives the repository a memory
 * of what it has already read, not here.
 *
 * @internal
 */
final class BindingAttributeLoader
{
	private readonly ActivityRepositoryInterface $activityRepository;

	private readonly ActivityBindingRepository $bindingRepository;

	/** @var array<int, ActivityBinding[]> bindings of the activities that have been read */
	private array $bindings = [];

	/** @var array<int, ActivityBinding|null> owner of the activities whose owner columns have been read */
	private array $owners = [];

	public function __construct(
		?ActivityRepositoryInterface $activityRepository = null,
		?ActivityBindingRepository $bindingRepository = null,
	)
	{
		$this->activityRepository = $activityRepository ?? new ActivityRepository();
		$this->bindingRepository = $bindingRepository ?? new ActivityBindingRepository($this->activityRepository);
	}

	/**
	 * Reads what the checks over these activities will need, in a number of queries that does not grow with
	 * the number of activities: the bindings, the owner columns of the activities left without a binding, and
	 * the permission attributes of every object involved.
	 *
	 * What is already known is not read again, so preloading twice costs one query set, and preloading a set
	 * that is already covered costs nothing.
	 *
	 * @param int[] $activityIds
	 */
	public function preload(int $userId, array $activityIds): void
	{
		$ids = $this->normalizeIds($activityIds);
		if ($ids === [])
		{
			return;
		}

		$this->readBindings($this->idsWithUnknownBindings($ids));
		$this->readOwners($this->idsNeedingOwner($ids));
		$this->preloadPermissionAttributes($userId, $ids);
	}

	/**
	 * Bindings of the activity: the preloaded ones, or a read of its own for an activity outside the batch.
	 *
	 * @return ActivityBinding[]
	 */
	public function getBindings(int $activityId): array
	{
		if (!array_key_exists($activityId, $this->bindings))
		{
			$this->bindings[$activityId] = $this->bindingRepository->getByActivityId($activityId);
		}

		return $this->bindings[$activityId];
	}

	/**
	 * The owner columns of the activity as one binding, for an activity that has no binding row left, or null
	 * when the columns name no owner either.
	 */
	public function getOwner(int $activityId): ?ActivityBinding
	{
		if (!array_key_exists($activityId, $this->owners))
		{
			$this->owners[$activityId] = $this->activityRepository->getOwners([$activityId])[$activityId] ?? null;
		}

		return $this->owners[$activityId];
	}

	/**
	 * Forgets what was read about this activity, because its bindings have changed.
	 *
	 * The permission attributes are not forgotten with them: they belong to the bound objects and not to the
	 * activity, and the machinery holding them drops them when the object itself changes.
	 */
	public function forget(int $activityId): void
	{
		unset($this->bindings[$activityId], $this->owners[$activityId]);
	}

	/**
	 * @param int[] $activityIds
	 * @return int[] the ids of the batch: positive and each one once. An id no activity can have is nothing to
	 *         read, and the check of such an id is refused before it asks anything.
	 */
	private function normalizeIds(array $activityIds): array
	{
		$ids = [];
		foreach ($activityIds as $activityId)
		{
			$activityId = (int)$activityId;
			if ($activityId > 0)
			{
				$ids[$activityId] = $activityId;
			}
		}

		return array_values($ids);
	}

	/**
	 * @param int[] $ids
	 */
	private function readBindings(array $ids): void
	{
		if ($ids === [])
		{
			return;
		}

		$bindings = $this->bindingRepository->getByActivityIds($ids);
		foreach ($ids as $activityId)
		{
			$this->bindings[$activityId] = $bindings[$activityId] ?? [];
		}
	}

	/**
	 * @param int[] $ids
	 */
	private function readOwners(array $ids): void
	{
		if ($ids === [])
		{
			return;
		}

		$owners = $this->activityRepository->getOwners($ids);
		foreach ($ids as $activityId)
		{
			$this->owners[$activityId] = $owners[$activityId] ?? null;
		}
	}

	/**
	 * @param int[] $ids
	 * @return int[]
	 */
	private function idsWithUnknownBindings(array $ids): array
	{
		return array_values(
			array_filter($ids, fn(int $activityId): bool => !array_key_exists($activityId, $this->bindings)),
		);
	}

	/**
	 * The ids of the batch whose owner columns the decision will reach for and nothing has read yet: an
	 * activity is judged by its owner only when it has no binding left.
	 *
	 * Asked of the whole batch rather than of what was just read, so that an activity whose bindings are known
	 * to be empty from an earlier single check joins the batch read instead of costing a query of its own.
	 *
	 * @param int[] $ids
	 * @return int[]
	 */
	private function idsNeedingOwner(array $ids): array
	{
		return array_values(
			array_filter(
				$ids,
				fn(int $activityId): bool
					=> ($this->bindings[$activityId] ?? []) === [] && !array_key_exists($activityId, $this->owners),
			),
		);
	}

	/**
	 * Warms the permission attributes of every object the activities belong to, one call per kind of object -
	 * the same batch the checks that follow would otherwise make one object at a time.
	 *
	 * An object whose attributes come back empty - a deleted one, above all - is remembered by nobody, so the
	 * checks over it read again however early it was warmed.
	 *
	 * @param int[] $ids
	 */
	private function preloadPermissionAttributes(int $userId, array $ids): void
	{
		$objectIdsByType = [];
		foreach ($ids as $activityId)
		{
			foreach ($this->ownersOf($activityId) as $owner)
			{
				$objectIdsByType[$owner->getOwnerType()->getId()][$owner->getOwnerId()] = $owner->getOwnerId();
			}
		}

		if ($objectIdsByType === [])
		{
			return;
		}

		$itemPermissions = Container::getInstance()->getUserPermissions($userId)->item();
		foreach ($objectIdsByType as $entityTypeId => $objectIds)
		{
			$itemPermissions->preloadPermissionAttributes($entityTypeId, array_values($objectIds));
		}
	}

	/**
	 * The objects the activity belongs to, as the order of the decision sees them: its bindings, or the owner
	 * of its row when it has none.
	 *
	 * @return ActivityBinding[]
	 */
	private function ownersOf(int $activityId): array
	{
		$bindings = $this->bindings[$activityId] ?? [];
		if ($bindings !== [])
		{
			return $bindings;
		}

		$owner = $this->owners[$activityId] ?? null;

		return $owner === null ? [] : [$owner];
	}
}
