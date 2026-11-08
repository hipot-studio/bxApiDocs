<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Repository\Activity;

use Bitrix\Crm\V2\Internal\Entity\Activity\Activity;
use Bitrix\Crm\V2\Internal\Entity\Activity\ProviderPayload;
use Bitrix\Crm\V2\Internal\Entity\Activity\StorageFieldTransit;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;

/**
 * The storage boundary of an activity with a memory of the hit: a row read once answers every later
 * question about it, and a batch read once answers the questions about all of them.
 *
 * WHAT IT IS FOR. The permission machinery asks about the same activity again and again within one hit -
 * the verdict of the provider is typed by the model of the activity, the bypass of the responsible person
 * compares one column of it, and the five operations are asked about one after another. Without a memory
 * every one of those is a row of `b_crm_act` of its own, and a page of activities pays it per activity: the
 * batch preload of the access model closes the bindings, the owner columns and the permission attributes,
 * and this closes the rows themselves. One row read for everybody who asks is the whole point - two reads are
 * two passes over the table.
 *
 * WHAT IS HANDED OUT IS A COPY, AND THE REMEMBERED MODEL LEAVES THIS OBJECT NEVER. The model of an activity is
 * changeable - it has setters and it tracks what was set - so a reader that changed the one it was given would
 * change what the next reader is answered with, and a decision about rights would be taken on a row nobody
 * ever wrote. What the memory saves is the read of the table and not the building of the model, so the copy
 * costs nothing the memory is there for ({@see Activity::__clone()}).
 *
 * WHAT IS REMEMBERED AND UNDER WHAT. A row is remembered per activity and per projection, because a
 * projection is what the model carries: a narrow read answers with a model whose other fields are unset,
 * and handing it back to whoever asked for the whole row would be handing back an activity with holes in
 * it. The whole row is the one projection that serves the others - it is a superset of every one of them -
 * so a batch read of whole rows makes the narrow reads that follow free. An id no row answers for is
 * remembered as such: the absence of a row is a fact about the id and not about the projection.
 *
 * HOW LONG IT LIVES AND WHAT INVALIDATES IT. As long as this object, which is at most the hit. A write
 * through this very instance forgets what it changed; a write anywhere else does not, so whoever changed an
 * activity behind this repository says {@see self::forget()} - the same call, and from the same place, that
 * makes the access model forget its decisions about that activity.
 *
 * @internal
 */
final class RememberingActivityRepository implements ActivityRepositoryInterface
{
	/** The projection of every core field, and the one that serves the narrower ones. */
	private const WHOLE_ROW = '*';

	private readonly ActivityRepositoryInterface $activityRepository;

	/** @var array<int, array<string, Activity>> the models read in this hit, by activity id and projection */
	private array $activities = [];

	/** @var array<int, true> the ids this hit found no row for */
	private array $missing = [];

	public function __construct(?ActivityRepositoryInterface $activityRepository = null)
	{
		$this->activityRepository = $activityRepository ?? new ActivityRepository();
	}

	public function getStateById(int $id): ?array
	{
		return $this->activityRepository->getStateById($id);
	}

	public function getById(int $id, ?array $fieldNames = null): ?Activity
	{
		if ($id <= 0)
		{
			return $this->activityRepository->getById($id, $fieldNames);
		}

		$projection = $this->projectionKey($fieldNames);
		if (isset($this->missing[$id]))
		{
			return null;
		}

		$remembered = $this->remembered($id, $projection);
		if ($remembered !== null)
		{
			return self::copyOf($remembered);
		}

		$activity = $this->activityRepository->getById($id, $fieldNames);
		$this->remember($id, $projection, $activity);

		return self::copyOf($activity);
	}

	/**
	 * Only the activities this hit knows nothing about reach the database, so preloading a set twice costs
	 * one query set and preloading a set that is already covered costs nothing.
	 */
	public function getByIds(array $ids, ?array $fieldNames = null): array
	{
		$wanted = $this->normalizeIds($ids);
		if ($wanted === [])
		{
			return $this->activityRepository->getByIds($ids, $fieldNames);
		}

		$projection = $this->projectionKey($fieldNames);

		$unknown = [];
		foreach ($wanted as $id)
		{
			if (!isset($this->missing[$id]) && $this->remembered($id, $projection) === null)
			{
				$unknown[] = $id;
			}
		}

		if ($unknown !== [])
		{
			$read = $this->activityRepository->getByIds($unknown, $fieldNames);
			foreach ($unknown as $id)
			{
				$this->remember($id, $projection, $read[$id] ?? null);
			}
		}

		$activities = [];
		foreach ($wanted as $id)
		{
			$activity = $this->remembered($id, $projection);
			if ($activity !== null)
			{
				$activities[$id] = self::copyOf($activity);
			}
		}

		return $activities;
	}

	/**
	 * Whether a row answers for this id, asked as cheaply as the hit allows: an id already read or preloaded is
	 * answered out of the memory and one already found missing out of the memory of the absences, so the batch
	 * that warms the rows makes this free for every id in it.
	 *
	 * An id nothing is known about is read WHOLE, because the whole row is the projection serving every question
	 * that follows - a narrow read here would leave the next one to pay for a second pass.
	 */
	public function exists(int $id): bool
	{
		if ($id <= 0 || isset($this->missing[$id]))
		{
			return false;
		}

		if (isset($this->activities[$id]))
		{
			return true;
		}

		$activity = $this->activityRepository->getById($id);
		$this->remember($id, self::WHOLE_ROW, $activity);

		return $activity !== null;
	}

	/**
	 * Not remembered: a query answers with the rows that match it at the moment it runs, and an answer of
	 * this kind cannot be recognized in a memory keyed by an activity.
	 */
	public function getList(
		array|ConditionTree|null $filter = null,
		?array $order = null,
		?int $limit = null,
		?int $offset = null,
		?array $fieldNames = null,
	): array
	{
		return $this->activityRepository->getList($filter, $order, $limit, $offset, $fieldNames);
	}

	public function getOwners(array $ids): array
	{
		return $this->activityRepository->getOwners($ids);
	}

	public function getNotify(int $id): ?array
	{
		return $this->activityRepository->getNotify($id);
	}

	public function getProviderPayload(int $id): ?ProviderPayload
	{
		return $this->activityRepository->getProviderPayload($id);
	}

	public function getThreadId(int $id): ?int
	{
		return $this->activityRepository->getThreadId($id);
	}

	/** The chain of an activity is a storage column no model carries, so what is remembered stays valid. */
	public function startOwnThread(int $activityId): void
	{
		$this->activityRepository->startOwnThread($activityId);
	}

	/** Likewise. */
	public function mergeThreadInto(int $activityId, int $threadId): void
	{
		$this->activityRepository->mergeThreadInto($activityId, $threadId);
	}

	public function add(
		Activity $activity,
		?ProviderPayload $payload = null,
		?StorageFieldTransit $transit = null,
	): int
	{
		return $this->activityRepository->add($activity, $payload, $transit);
	}

	public function syncOwnerColumns(
		int $activityId,
		array $bindings,
		?StorageFieldTransit $transit = null,
	): void
	{
		$this->activityRepository->syncOwnerColumns($activityId, $bindings, $transit);
		$this->forget($activityId);
	}

	public function update(
		Activity $activity,
		?ProviderPayload $payload = null,
		?StorageFieldTransit $transit = null,
	): void
	{
		$this->activityRepository->update($activity, $payload, $transit);
		$this->forget((int)$activity->getId());
	}

	/** Forgets the row of this activity, because it has changed or may have changed. */
	public function forget(int $activityId): void
	{
		unset($this->activities[$activityId], $this->missing[$activityId]);
	}

	/**
	 * The remembered model of this projection: the one read under it, or the whole row, which carries every
	 * field a narrower projection could name.
	 *
	 * The model itself, never handed out as it is - {@see self::copyOf()} is what leaves this object.
	 */
	private function remembered(int $id, string $projection): ?Activity
	{
		return $this->activities[$id][$projection] ?? $this->activities[$id][self::WHOLE_ROW] ?? null;
	}

	/** The copy a reader gets, so that what it does to the model it holds reaches no other reader. */
	private static function copyOf(?Activity $activity): ?Activity
	{
		return $activity === null ? null : clone $activity;
	}

	private function remember(int $id, string $projection, ?Activity $activity): void
	{
		if ($activity === null)
		{
			unset($this->activities[$id]);
			$this->missing[$id] = true;

			return;
		}

		unset($this->missing[$id]);
		$this->activities[$id][$projection] = $activity;
	}

	/**
	 * The key of the projection: the same set of fields named in another order is the same projection.
	 *
	 * A field name the table does not store is refused here too, and before the memory is consulted: a
	 * remembered row must not turn the error of the storage boundary into an answer.
	 *
	 * @param string[]|null $fieldNames
	 * @throws ArgumentException on a field name that is not stored in the table.
	 */
	private function projectionKey(?array $fieldNames): string
	{
		if ($fieldNames === null)
		{
			return self::WHOLE_ROW;
		}

		$names = [];
		foreach ($fieldNames as $fieldName)
		{
			$fieldName = (string)$fieldName;
			if (ActivityFieldMap::getColumn($fieldName) === null)
			{
				throw new ArgumentException("'{$fieldName}' is not stored in a column of an activity");
			}

			$names[$fieldName] = $fieldName;
		}

		$names = array_values($names);
		sort($names);

		return implode(',', $names);
	}

	/**
	 * @param int[] $ids
	 * @return int[] positive and each one once, in the order they were asked for
	 */
	private function normalizeIds(array $ids): array
	{
		$normalized = [];
		foreach ($ids as $id)
		{
			$id = (int)$id;
			if ($id > 0)
			{
				$normalized[$id] = $id;
			}
		}

		return array_values($normalized);
	}
}
