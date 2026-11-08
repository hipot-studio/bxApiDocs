<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Repository\Activity;

use Bitrix\Crm\V2\Internal\Entity\Activity\Activity;
use Bitrix\Crm\V2\Internal\Entity\Activity\ActivityBinding;
use Bitrix\Crm\V2\Internal\Entity\Activity\ProviderPayload;
use Bitrix\Crm\V2\Internal\Entity\Activity\StorageFieldTransit;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;

/**
 * The storage boundary of one activity: the row of `b_crm_act` behind it, read and written as typed V2
 * values. No ORM object crosses this interface in either direction.
 *
 * TWO NAMING SYSTEMS, ON PURPOSE. What is selected is named in the model - `$fieldNames` are field names
 * of {@see Activity} - because the result is a model. What is filtered and ordered is named in the
 * storage: a filter is built by the reading side (the permission machinery among others) in terms of the
 * table, and {@see ActivityFieldMap::getColumn()} resolves a model name into its column for whoever
 * needs it.
 *
 * WHAT THE STORAGE BOUNDARY DOES NOT DO. It publishes no event, runs no side effect, opens no
 * transaction and validates nothing beyond its own preconditions: the order "write, read back, then the
 * effects" belongs to the pipeline. Two columns are not its business either - `SEARCH_CONTENT` belongs to
 * the search content builder and `STORAGE_ELEMENT_IDS` to the repository of the storage elements.
 *
 * @internal
 */
interface ActivityRepositoryInterface
{
	/** The typed model of the stored state, under {@see self::getStateById()}. */
	public const STATE_ACTIVITY = 'activity';

	/** The serialized fields of the stored state. */
	public const STATE_PAYLOAD = 'payload';

	/** The storage columns of the stored state that the model does not hold. */
	public const STATE_TRANSIT = 'transit';

	/**
	 * The whole stored state of the activity - the model, the serialized fields and the storage columns
	 * neither of them owns - or null when there is no row with that id.
	 *
	 * WHY THE THREE TOGETHER. The pipelines of an update and of a delete need a state, not a model: the
	 * provider of an update is resolved from the legacy type of the stored row, the wrapper of a legacy
	 * provider projects all three parts back into the field array it hands over, and a state assembled out
	 * of three separate reads would be three rows as they stood at three moments. The columns of the search
	 * content and of the storage elements are not part of it - they belong to their own writers, exactly as
	 * they do on the way back.
	 *
	 * @return array{activity: Activity, payload: ProviderPayload, transit: StorageFieldTransit}|null keyed by
	 *         the three `STATE_*` constants.
	 */
	public function getStateById(int $id): ?array;

	/**
	 * The activity, or null when there is no row with that id.
	 *
	 * @param string[]|null $fieldNames field names of {@see Activity}; null selects every core field.
	 * @throws ArgumentException on a field name that is not stored in the table.
	 */
	public function getById(int $id, ?array $fieldNames = null): ?Activity;

	/**
	 * Reads the given activities in one query - the batch preload of the pipeline and of the access
	 * checks. Ids that have no row are absent from the result; an empty input does not hit the database.
	 *
	 * @param int[] $ids
	 * @param string[]|null $fieldNames field names of {@see Activity}; null selects every core field.
	 * @return array<int, Activity> by activity id
	 * @throws ArgumentException on a field name that is not stored in the table.
	 */
	public function getByIds(array $ids, ?array $fieldNames = null): array;

	/**
	 * @param array|ConditionTree|null $filter ORM filter of `b_crm_act`
	 * @param array<string, string>|null $order ORM-name keyed ASC/DESC map
	 * @param string[]|null $fieldNames field names of {@see Activity}; null selects every core field.
	 * @return Activity[] in the order of the query
	 * @throws ArgumentException on a field name that is not stored in the table.
	 */
	public function getList(
		array|ConditionTree|null $filter = null,
		?array $order = null,
		?int $limit = null,
		?int $offset = null,
		?array $fieldNames = null,
	): array;

	/**
	 * Owners of the given activities in one query - the batch preload the access checks need, which read
	 * the owner of an activity from the row rather than from its bindings.
	 *
	 * An activity whose owner columns are empty has no entry: legacy rows without an owner exist, and 0
	 * is not an owner.
	 *
	 * @param int[] $ids
	 * @return array<int, ActivityBinding> by activity id
	 */
	public function getOwners(array $ids): array;

	/**
	 * The notify pair of the activity, in the form {@see \Bitrix\Crm\V2\Internal\Entity\Activity\ActivityChangeSet}
	 * accepts, or null when there is no row with that id. The two columns are storage-only, so the model
	 * cannot carry them and the change set takes them from here.
	 *
	 * @return array{type: ?int, value: ?int}|null
	 */
	public function getNotify(int $id): ?array;

	/**
	 * The serialized fields of the activity as the provider layer consumes them, or null when there is no
	 * row with that id. The content is handed over exactly as the storage gave it: unpacking forbids
	 * instantiating objects, so a value written as one comes back as an inert placeholder that neither is
	 * an array nor equals false.
	 */
	public function getProviderPayload(int $id): ?ProviderPayload;

	/**
	 * The thread the activity belongs to, or null when there is no row with that id.
	 *
	 * The column is storage-only, so the model cannot carry it, and the one caller that needs it - the step
	 * attaching a new activity to its parent - needs the two facts this answer holds at once: whether the
	 * parent exists at all, and which thread it continues. A thread of zero is a stored value of legacy rows
	 * and not the same answer as "no such activity".
	 */
	public function getThreadId(int $id): ?int;

	/**
	 * Makes the activity the head of a chain of its own - `THREAD_ID = ID`, the value a chain of one activity
	 * has.
	 *
	 * The column is storage-only and the value is the identifier the storage has just handed out, so no
	 * caller can name it before the row exists: the step attaching a new activity to no parent runs below the
	 * insert and asks for this. That one column and nothing else is written - no audit stamp is bumped,
	 * because the write of the activity has already happened (crm_activity.php:229-232 writes it the same
	 * way, past the update path).
	 *
	 * @throws ArgumentException on a non-positive id, or when the write fails.
	 */
	public function startOwnThread(int $activityId): void;

	/**
	 * Moves every activity of the chain the given activity heads into another chain - `THREAD_ID = $threadId`
	 * for every row whose `THREAD_ID` is the id of that activity.
	 *
	 * An activity that had no parent is the head of a chain of its own, and everything answering to it follows
	 * it when it is attached to a parent: the step of an update that resolved the new chain asks for this right
	 * after the row itself has been written (crm_activity.php:673-679), and the row of the activity is no longer
	 * part of the set - its own column has just been written with the new chain.
	 *
	 * That one column and nothing else is written, for the same reason as above: whoever attached the activity
	 * has already written what it had to write, and legacy moves the chain with a statement of its own that
	 * touches no audit stamp. A chain of zero is a value stored rows of legacy really have, so it is accepted as
	 * the target.
	 *
	 * @throws ArgumentException on a non-positive id, or when the write fails.
	 */
	public function mergeThreadInto(int $activityId, int $threadId): void;

	/**
	 * Stores a new activity and returns its id.
	 *
	 * The values of the model are taken as they are: what the repository adds is the storage's own -
	 * the timestamps, the derived `STATUS` and `IS_HANDLEABLE`, the deadline sentinel, the owner columns
	 * kept in sync with the bindings, and the defaults of the columns the table declares as not nullable.
	 *
	 * @throws ArgumentException when a field the table cannot default is missing, or when the write fails.
	 */
	public function add(
		Activity $activity,
		?ProviderPayload $payload = null,
		?StorageFieldTransit $transit = null,
	): int;

	/**
	 * Brings the owner columns of the row in step with the given set of bindings, by the same rule the
	 * write path applies on an add and on an update. Those two columns and nothing else: no audit stamp is
	 * bumped, because whoever changed the bindings has already written what it had to write.
	 *
	 * The owner of an activity is a denormalization of its bindings that the permission SQL is built on, so
	 * it cannot be left behind when the set changes. The rule of deriving it lives here, next to the write
	 * path; the repository of the bindings delegates to this method instead of holding a second copy of it.
	 *
	 * AN OWNER THE OPERATION NAMED IS NOT OVERWRITTEN. The rule is the one of the write path in full - the
	 * pair of owner columns of the transit wins and the set decides only where the operation named none - so
	 * a write of the bindings can no longer undo what the same operation said about the owner. Handing no
	 * transit over means "nobody named one", which is what a writer outside a pipeline says.
	 *
	 * @param ActivityBinding[] $bindings the effective set of the activity; an empty set clears the owner.
	 * @param StorageFieldTransit|null $transit the storage columns of the operation, for the owner it named.
	 * @throws ArgumentException on a non-positive id, or when the write fails.
	 */
	public function syncOwnerColumns(
		int $activityId,
		array $bindings,
		?StorageFieldTransit $transit = null,
	): void;

	/**
	 * Stores the changed fields of the activity, plus the values the payload and the transit carry.
	 *
	 * Only what the caller touched is written: the fields the model marked as changed, and nothing else.
	 * A write with nothing to change is a no-op, and the columns legacy refuses to change on an update -
	 * the creation time, the author, the provider and the type of the activity - are dropped here too.
	 *
	 * @throws ArgumentException when the activity has no id, or when the write fails.
	 */
	public function update(
		Activity $activity,
		?ProviderPayload $payload = null,
		?StorageFieldTransit $transit = null,
	): void;
}
