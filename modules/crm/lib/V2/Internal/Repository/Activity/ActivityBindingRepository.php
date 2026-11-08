<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Repository\Activity;

use Bitrix\Crm\ActivityBindingTable;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\V2\Internal\Entity\Activity\ActivityBinding;
use Bitrix\Crm\V2\Internal\Entity\Activity\StorageFieldTransit;
use Bitrix\Crm\V2\Public\OwnerType;
use Bitrix\Main\Application;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Config\Option;
use CCrmOwnerType;
use Closure;
use Psr\Log\LoggerInterface;

/**
 * The rows of `b_crm_act_bind` behind one activity: which CRM objects it belongs to.
 *
 * WRITTEN AS A DIFFERENCE, and that is the one place where V2 departs from legacy on purpose (registry of
 * intentional deviations, F3/F4-BINDINGS). Legacy replaces the whole set on every save - it deletes every
 * row and inserts the effective set back - which leaves a window where the activity is bound to nothing,
 * gives every surviving binding a new row id, and recomputes the badges twice. Here only the difference is
 * applied: the rows that stay are not touched at all, so their ids stay too, and the consumers of the
 * change (the timeline, the last-communication index, the badges) hear about the changed subset once.
 *
 * WHAT IS KEPT EXACTLY AS LEGACY HAS IT: the selection rules of the incoming set. Duplicates collapse by
 * the owner key with the first occurrence winning, an owner of an undefined type is dropped, and a set
 * longer than the limit is trimmed silently to the largest owner ids - each with the notice legacy writes
 * for it. The effective set is the answer of the write, because the caller cannot know it beforehand.
 *
 * TWO NOTICES THAT ARE NOT THE SAME THING. Trimming by `max_bindings_count` says "wanted N, stored the
 * limit" and happens before anything is written. The threshold of ten is about the number of bindings
 * growing across it, and it is counted from the two full sizes - the rows the activity had and the
 * effective set - never from the changed subset: that is a different set and a different moment.
 *
 * WHAT THIS REPOSITORY DOES NOT DO. It recomputes no badge, no counter and no read model of uncompleted
 * activities, and it knows nothing about access rights: those effects belong to the steps of the pipeline,
 * which is also why the change is handed to a listener the caller supplies rather than acted upon here.
 * The owner columns of the activity are the one exception - they are a denormalization of this very set,
 * and the rule of deriving them lives in the repository of the activity, which this class delegates to.
 *
 * @internal
 */
final class ActivityBindingRepository
{
	/** The logger of the two notices; legacy writes both of them there (mysql/crm_activity.php:526, :544). */
	private const LOGGER_ID = 'ScenarioInvestigation';

	/** Number of bindings whose crossing from below is worth a notice (mysql/crm_activity.php:14). */
	private const BINDINGS_COUNT_TO_LOG = 10;

	private const MAX_BINDINGS_COUNT_OPTION = 'max_bindings_count';
	private const DEFAULT_MAX_BINDINGS_COUNT = 100;

	private readonly ActivityRepositoryInterface $activityRepository;

	private ?LoggerInterface $logger;

	private readonly ?Closure $onBindingsChanged;

	/**
	 * @param LoggerInterface|null $logger null takes the logger legacy writes the same notices to.
	 * @param Closure|null $onBindingsChanged
	 *        `function (int $activityId, ActivityBinding[] $added, ActivityBinding[] $removed): void` -
	 *        called once per write that changed something, with the changed subsets only. This is how the
	 *        storage layer reports a change without knowing what anybody recomputes because of it.
	 */
	public function __construct(
		?ActivityRepositoryInterface $activityRepository = null,
		?LoggerInterface $logger = null,
		?Closure $onBindingsChanged = null,
	)
	{
		$this->activityRepository = $activityRepository ?? new ActivityRepository();
		$this->logger = $logger;
		$this->onBindingsChanged = $onBindingsChanged;
	}

	/**
	 * Bindings of the activity, in the order of their rows. A binding a row cannot describe - an owner of
	 * a non-positive id or of a non-positive type, which legacy rows do carry - has no place in the result.
	 *
	 * @return ActivityBinding[]
	 * @throws ArgumentException on a non-positive id.
	 */
	public function getByActivityId(int $activityId): array
	{
		return array_values($this->readRows($activityId)['bindings']);
	}

	/**
	 * Bindings of the given activities in one query - the batch read the access checks preload with before
	 * they go over a set of activities one by one.
	 *
	 * An activity with no binding row is absent from the result, as is an id no activity can have: a batch is
	 * a list of what to read and not a claim about every element of it. A row no binding can describe is
	 * skipped by the same rule a single read skips it by.
	 *
	 * @param int[] $activityIds
	 * @return array<int, ActivityBinding[]> by activity id, each set in the order of its rows
	 */
	public function getByActivityIds(array $activityIds): array
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

		if ($ids === [])
		{
			return [];
		}

		$rows =
			ActivityBindingTable::query()
				->setSelect(['ACTIVITY_ID', 'OWNER_ID', 'OWNER_TYPE_ID'])
				->whereIn('ACTIVITY_ID', array_values($ids))
				->setOrder(['ID' => 'ASC'])
				->fetchAll()
		;

		$bindings = [];
		foreach ($rows as $row)
		{
			$binding = $this->bindingFromRow($row);
			if ($binding === null)
			{
				continue;
			}

			$bindings[(int)$row['ACTIVITY_ID']][$binding->getKey()] ??= $binding;
		}

		return array_map('array_values', $bindings);
	}

	/**
	 * Stores the given set as the set of the activity and answers with what it effectively became.
	 *
	 * The order of the steps is normative, and ALG-02 names them: `dedupByOwnerKey`, `filterValid`,
	 * `trimToLimit`, `readBindings`, `deleteRows`, `insertRows`, `syncOwnerFields`, `notifyBindingsChanged`,
	 * `logBindingsCountThresholdNotice`. The incoming set is reduced to the effective one, the difference
	 * against the stored rows is computed, and only that difference is written. A write that changes nothing
	 * does exactly nothing - no query, no owner synchronization, no notification, no notice.
	 *
	 * THE OWNER THE OPERATION NAMED SURVIVES THE WRITE. The owner columns are brought in step with the
	 * effective set by the repository of the activity, and it is told what the operation said about the
	 * owner rather than only what it is bound to: an explicit owner beats the one derived from a set, and
	 * a write of the bindings is not the place that decision is reversed.
	 *
	 * @param ActivityBinding[] $bindings
	 * @param StorageFieldTransit|null $transit the storage columns of the operation, carried through to the
	 *        owner columns; null is a writer that named no owner.
	 * @return ActivityBinding[] the effective set, in the order it was stored in
	 * @throws ArgumentException on a non-positive id, or when a write fails.
	 */
	public function save(int $activityId, array $bindings, ?StorageFieldTransit $transit = null): array
	{
		$this->assertActivityId($activityId);

		$effective = $this->trimToLimit($activityId, $this->filterValid($this->dedupByOwnerKey($bindings)));
		$stored = $this->readRows($activityId);

		$toAdd = array_diff_key($effective, $stored['rowIds']);
		$toRemove = array_diff_key($stored['rowIds'], $effective);
		$rowIdsToDelete = array_merge(
			array_merge([], ...array_values($toRemove)),
			$this->surplusRowIds(array_intersect_key($stored['rowIds'], $effective)),
			$stored['unboundRowIds'],
		);

		if ($toAdd === [] && $rowIdsToDelete === [])
		{
			return array_values($effective);
		}

		$this->deleteRows($rowIdsToDelete);
		$this->insertRows($activityId, $toAdd);
		$this->activityRepository->syncOwnerColumns($activityId, array_values($effective), $transit);

		// A cleanup of rows no binding describes changes the rows and not the set, so there is nothing to
		// report: whoever listens recomputes what depends on the bindings, and those did not move.
		if ($toAdd !== [] || $toRemove !== [])
		{
			$this->notifyBindingsChanged(
				$activityId,
				array_values($toAdd),
				array_values(array_intersect_key($stored['bindings'], $toRemove)),
			);
		}

		$this->logBindingsCountThresholdNotice($activityId, $stored['rowCount'], count($effective));

		return array_values($effective);
	}

	/**
	 * Removes every row of the activity and reports the removal to the listener. The step of the pipeline
	 * that deletes an activity needs it: legacy drops the whole set from inside the deletion of the row
	 * (`DeleteBindings($ID, false)`, :2208-2266) and recomputes over it what depends on the set.
	 *
	 * THE OWNER COLUMNS OF THE ACTIVITY ARE NOT SYNCHRONIZED, and that is no omission. Legacy leaves them
	 * alone here, they are a denormalization of a set that no longer exists, and the row holding them is
	 * erased right after this.
	 *
	 * THE REMOVAL IS REPORTED EVEN WHERE THERE WAS NOTHING TO REMOVE, exactly as legacy reports it: the
	 * consumers of the change are told on every deletion, and the badges of the activity are recomputed by
	 * that call whether or not a binding was there.
	 *
	 * @throws ArgumentException on a non-positive id.
	 */
	public function deleteByActivityId(int $activityId): void
	{
		$this->assertActivityId($activityId);

		$stored = $this->readRows($activityId);

		$this->deleteRows(array_merge(
			array_merge([], ...array_values($stored['rowIds'])),
			$stored['unboundRowIds'],
		));
		$this->notifyBindingsChanged($activityId, [], array_values($stored['bindings']));
	}

	//region the effective set

	/**
	 * The first occurrence of an owner wins, as it does in legacy (`SaveBindings`, :2839-2853). The order
	 * of what is left is the order the caller gave, and it matters: the owner of the activity is derived
	 * from the set, and that rule reads its ends.
	 *
	 * @param ActivityBinding[] $bindings
	 * @return array<string, ActivityBinding> by owner key
	 */
	private function dedupByOwnerKey(array $bindings): array
	{
		$byKey = [];
		foreach ($bindings as $binding)
		{
			$byKey[$binding->getKey()] ??= $binding;
		}

		return $byKey;
	}

	/**
	 * An owner of an undefined type is no owner. The other half of the legacy filter - a positive owner id -
	 * is already an invariant of {@see ActivityBinding}.
	 *
	 * The check goes through the legacy resolver and not through {@see OwnerType}, which has none of its
	 * own: the storage has to accept exactly the types legacy accepted, suspended dynamic ones included.
	 *
	 * @param array<string, ActivityBinding> $bindings
	 * @return array<string, ActivityBinding>
	 */
	private function filterValid(array $bindings): array
	{
		return array_filter(
			$bindings,
			static fn(ActivityBinding $binding): bool => CCrmOwnerType::IsDefined($binding->getOwnerType()->getId()),
		);
	}

	/**
	 * Cuts the set down to the limit the way legacy does (`trimBindingsArray`, mysql:511-518): the largest
	 * owner ids survive, the rest disappear without an error, and the loss is only visible in the log.
	 *
	 * @param array<string, ActivityBinding> $bindings
	 * @return array<string, ActivityBinding>
	 */
	private function trimToLimit(int $activityId, array $bindings): array
	{
		$limit = (int)Option::get('crm', self::MAX_BINDINGS_COUNT_OPTION, self::DEFAULT_MAX_BINDINGS_COUNT);
		$desiredCount = count($bindings);
		if ($desiredCount <= $limit)
		{
			return $bindings;
		}

		uasort(
			$bindings,
			static fn(ActivityBinding $one, ActivityBinding $other): int
				=> $other->getOwnerId() <=> $one->getOwnerId(),
		);

		$this->getLogger()->notice(
			'Activity #{activityId}: {desiredCount} bindings were given, {storedCount} stored - the rest were'
			. ' trimmed to the limit',
			[
				'activityId' => $activityId,
				'desiredCount' => $desiredCount,
				'storedCount' => $limit,
			],
		);

		return array_slice($bindings, 0, $limit, true);
	}

	//endregion

	//region storage

	/**
	 * The stored rows of the activity: the ones a binding describes grouped by its key, and the ones no
	 * binding can describe - an owner of a non-positive id or type, which legacy rows do carry - apart. The
	 * second group belongs to no set, so it is neither read nor reported, and a write removes it exactly as
	 * the legacy rewrite of the whole set did.
	 *
	 * The count of the rows is part of the answer, and it is the count of the rows rather than of the keys:
	 * the table has no unique index over the owner pair, so a key can own several rows, and the threshold
	 * notice is counted from the same number legacy counted it from.
	 *
	 * @return array{
	 *     rowIds: array<string, int[]>,
	 *     bindings: array<string, ActivityBinding>,
	 *     unboundRowIds: int[],
	 *     rowCount: int,
	 * }
	 * @throws ArgumentException on a non-positive id.
	 */
	private function readRows(int $activityId): array
	{
		$this->assertActivityId($activityId);

		$rows =
			ActivityBindingTable::query()
				->setSelect(['ID', 'OWNER_ID', 'OWNER_TYPE_ID'])
				->where('ACTIVITY_ID', $activityId)
				->setOrder(['ID' => 'ASC'])
				->fetchAll()
		;

		$rowIds = [];
		$bindings = [];
		$unboundRowIds = [];
		foreach ($rows as $row)
		{
			$rowId = (int)$row['ID'];

			$binding = $this->bindingFromRow($row);
			if ($binding === null)
			{
				$unboundRowIds[] = $rowId;
				continue;
			}

			$key = $binding->getKey();
			$rowIds[$key][] = $rowId;
			$bindings[$key] ??= $binding;
		}

		return [
			'rowIds' => $rowIds,
			'bindings' => $bindings,
			'unboundRowIds' => $unboundRowIds,
			'rowCount' => count($rows),
		];
	}

	/**
	 * The binding a row describes, or null for a row none can - an owner of a non-positive id or of a
	 * non-positive type, which legacy rows do carry.
	 *
	 * @param array<string, mixed> $row
	 */
	private function bindingFromRow(array $row): ?ActivityBinding
	{
		$ownerId = (int)($row['OWNER_ID'] ?? 0);
		$ownerTypeId = (int)($row['OWNER_TYPE_ID'] ?? 0);
		if ($ownerId <= 0 || $ownerTypeId <= 0)
		{
			return null;
		}

		return new ActivityBinding(OwnerType::fromId($ownerTypeId), $ownerId);
	}

	/**
	 * Rows a surviving binding has beyond its first one. Nothing writes them any more, but rows of that
	 * shape exist, and legacy collapsed them by rewriting the whole set - the difference has to collapse
	 * them too, or the stored set would no longer be the set legacy would have left.
	 *
	 * @param array<string, int[]> $rowIdsOfKeptKeys
	 * @return int[]
	 */
	private function surplusRowIds(array $rowIdsOfKeptKeys): array
	{
		$surplus = [];
		foreach ($rowIdsOfKeptKeys as $rowIds)
		{
			$surplus = array_merge($surplus, array_slice($rowIds, 1));
		}

		return $surplus;
	}

	/**
	 * @param int[] $rowIds
	 */
	private function deleteRows(array $rowIds): void
	{
		if ($rowIds === [])
		{
			return;
		}

		$connection = Application::getConnection();
		$helper = $connection->getSqlHelper();
		$ids = implode(',', array_map('intval', $rowIds));

		$connection->queryExecute(
			'DELETE FROM ' . $helper->quote(ActivityBindingTable::getTableName())
			. ' WHERE ' . $helper->quote('ID') . ' IN (' . $ids . ')',
		);
	}

	/**
	 * @param array<string, ActivityBinding> $bindings
	 * @throws ArgumentException when the write fails.
	 */
	private function insertRows(int $activityId, array $bindings): void
	{
		if ($bindings === [])
		{
			return;
		}

		$rows = [];
		foreach ($bindings as $binding)
		{
			$rows[] = [
				'ACTIVITY_ID' => $activityId,
				'OWNER_ID' => $binding->getOwnerId(),
				'OWNER_TYPE_ID' => $binding->getOwnerType()->getId(),
			];
		}

		$result = ActivityBindingTable::addMulti($rows, true);
		if (!$result->isSuccess())
		{
			throw new ArgumentException(
				"Could not store the bindings of activity #{$activityId}: "
				. implode('; ', $result->getErrorMessages()),
			);
		}
	}

	//endregion

	//region reporting the change

	/**
	 * @param ActivityBinding[] $added
	 * @param ActivityBinding[] $removed
	 */
	private function notifyBindingsChanged(int $activityId, array $added, array $removed): void
	{
		if ($this->onBindingsChanged === null)
		{
			return;
		}

		($this->onBindingsChanged)($activityId, $added, $removed);
	}

	/**
	 * The notice legacy writes when the number of bindings of an activity grows across the threshold
	 * (`logActivityWithBindings`, mysql:533-549).
	 *
	 * Both counts are the full sizes of the two sets and not the size of the difference: the message is
	 * about how many bindings the activity has, so counting the changed subset instead would fire at other
	 * moments and report other numbers.
	 */
	private function logBindingsCountThresholdNotice(int $activityId, int $storedCount, int $effectiveCount): void
	{
		if ($storedCount >= self::BINDINGS_COUNT_TO_LOG || $effectiveCount < self::BINDINGS_COUNT_TO_LOG)
		{
			return;
		}

		$this->getLogger()->notice(
			'Activity #{activityId} changed the number of its bindings from {storedCount} to'
			. ' {effectiveCount}',
			[
				'activityId' => $activityId,
				'storedCount' => $storedCount,
				'effectiveCount' => $effectiveCount,
			],
		);
	}

	private function getLogger(): LoggerInterface
	{
		$this->logger ??= Container::getInstance()->getLogger(self::LOGGER_ID);

		return $this->logger;
	}

	//endregion

	/**
	 * @throws ArgumentException on a non-positive id.
	 */
	private function assertActivityId(int $activityId): void
	{
		if ($activityId <= 0)
		{
			throw new ArgumentException('Bindings belong to an activity that has an id');
		}
	}
}
