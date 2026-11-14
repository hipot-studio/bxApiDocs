<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Repository\Activity;

use Bitrix\Crm\ActivityBindingTable;
use Bitrix\Crm\ActivityTable;
use Bitrix\Crm\V2\Internal\Entity\Activity\Activity;
use Bitrix\Crm\V2\Internal\Entity\Activity\ActivityBinding;
use Bitrix\Crm\V2\Internal\Entity\Activity\Enum\CompletionStatus;
use Bitrix\Crm\V2\Internal\Entity\Activity\ProviderPayload;
use Bitrix\Crm\V2\Internal\Entity\Activity\StorageFieldTransit;
use Bitrix\Crm\V2\Internal\Service\Activity\Read\ActivityFilter;
use Bitrix\Crm\V2\Internal\Service\Activity\Read\ActivitySort;
use Bitrix\Crm\V2\Public\OwnerType;
use Bitrix\Main\Application;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Objectify\EntityObject;
use Bitrix\Main\ORM\Query\Filter\Condition;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Type\DateTime;
use CCrmActivityNotifyType;
use CCrmActivityType;
use Closure;
use Throwable;

/**
 * The row of `b_crm_act` behind an activity, read and written through {@see ActivityTable}.
 *
 * WHAT THE STORAGE LAYER OWNS. Everything the columns need and the domain does not say: the audit
 * timestamps, the pair `STATUS` / `IS_HANDLEABLE` derived from the completion flag, the max-date sentinel a
 * new row carries where nobody handed a deadline over - deriving one is the business of the check of the
 * fields - the owner columns kept in step with the bindings, and the legacy shape of the serialized
 * fields. Names, categories and value correspondences all come from {@see ActivityFieldMap} - this class
 * holds no second copy of them.
 *
 * THE DERIVED PAIR IS NOT PURE, and reproducing that is the point. `STATUS` and `IS_HANDLEABLE` are
 * recomputed only when the completion flag takes part in the write and only when the caller did not hand
 * a value over: an unconditional recomputation would silently break the gate the "wait" provider builds
 * on, which writes `IS_HANDLEABLE = 'N'` on an activity that is not completed.
 *
 * WHY ONE COLUMN LEAVES THE ORM PATH IN BOTH DIRECTIONS. `SETTINGS` has to hold what the legacy write path
 * leaves there - one plain serialization - while the field map of the entity would push every string of it
 * through the emoji escaping first, so the column is written past the field map
 * ({@see self::writeSettings()}). It is read past it as well ({@see self::unserializeSettings()}), because
 * the unpacking callback of the same field decodes those escapes: read decoded and written back plain, an
 * escaped column would come out of a round trip rewritten. Legacy reads the column plain too
 * (`CCrmActivityDbResult::fetchOneRecord`), so both ends of this boundary now say what legacy says. The
 * other two serialized columns need no such treatment: the field map serializes `PROVIDER_PARAMS` exactly
 * as legacy does and hands `PROVIDER_DATA` over verbatim.
 *
 * AND WHY THAT ONE COLUMN IS THE ONE PLACE THIS BOUNDARY OPENS A TRANSACTION. Leaving the ORM path makes a
 * write carrying `SETTINGS` two statements instead of one, and two statements can half-succeed: legacy
 * writes the column in the same statement as everything else and needs no transaction for it. So a write
 * carrying the column is wrapped and a write without it is not ({@see self::asOneWrite()}) - the pair either
 * lands whole or leaves nothing, which is the guarantee legacy gets from its single statement.
 *
 * @internal
 */
final class ActivityRepository implements ActivityRepositoryInterface
{
	/** Core columns the repository decides on its own instead of taking them from the model. */
	private const DERIVED_COLUMNS = ['ID', 'CREATED', 'LAST_UPDATED', 'DEADLINE', 'STATUS', 'PRIORITY'];

	/**
	 * Columns legacy strips from an update and, for the provider, puts back with the value the activity
	 * already had (crm_activity.php:2117-2146): once an activity exists, neither its author nor its
	 * provider changes.
	 */
	private const IMMUTABLE_ON_UPDATE_COLUMNS = ['PROVIDER_ID', 'AUTHOR_ID'];

	private const OWNER_ID_COLUMN = 'OWNER_ID';
	private const OWNER_TYPE_ID_COLUMN = 'OWNER_TYPE_ID';
	private const HANDLEABLE_COLUMN = 'IS_HANDLEABLE';
	private const STORAGE_ELEMENT_IDS_COLUMN = 'STORAGE_ELEMENT_IDS';

	/**
	 * Columns of the sort that are NOT NULL in the table on every storage, so that no NULL ordering applies to them.
	 * `START_TIME`, `END_TIME` and `DEADLINE` are nullable.
	 */
	private const NOT_NULL_SORT_COLUMNS = ['ID', 'CREATED', 'LAST_UPDATED'];

	/** Alias the raw `SETTINGS` is selected under, past the unpacking callback of the field map. */
	private const RAW_SETTINGS_ALIAS = 'SETTINGS_RAW';

	public function getStateById(int $id): ?array
	{
		$transitColumns = self::transitColumnsOfARow();
		$object = $this->fetchOne(
			array_values(array_unique(array_merge(
				ActivityFieldMap::getCoreColumns(),
				$transitColumns,
				[self::STORAGE_ELEMENT_IDS_COLUMN],
			))),
			$id,
		);
		if ($object === null)
		{
			return null;
		}

		$transit = new StorageFieldTransit();
		foreach ($transitColumns as $column)
		{
			$transit->set($column, self::transitValueOf($column, $object->get($column)));
		}

		return [
			self::STATE_ACTIVITY => $this->hydrate($object, ActivityFieldMap::getCoreColumns()),
			self::STATE_PAYLOAD => $this->getProviderPayload($id) ?? new ProviderPayload(),
			self::STATE_TRANSIT => $transit,
			self::STATE_STORAGE_ELEMENT_IDS => self::storageElementIdsOf(
				$object->get(self::STORAGE_ELEMENT_IDS_COLUMN),
			),
		];
	}

	public function getById(int $id, ?array $fieldNames = null): ?Activity
	{
		return $this->getByIds([$id], $fieldNames)[$id] ?? null;
	}

	public function getByIds(array $ids, ?array $fieldNames = null): array
	{
		$ids = $this->normalizeIds($ids);
		if ($ids === [])
		{
			return [];
		}

		$columns = $this->resolveSelect($fieldNames);

		$activities = [];
		foreach ($this->fetchObjects($columns, (new ConditionTree())->whereIn('ID', $ids)) as $object)
		{
			$activities[(int)$object->get('ID')] = $this->hydrate($object, $columns);
		}

		return $activities;
	}

	public function getList(
		array|ConditionTree|null $filter = null,
		?array $order = null,
		?int $limit = null,
		?int $offset = null,
		?array $fieldNames = null,
	): array
	{
		$columns = $this->resolveSelect($fieldNames);

		$activities = [];
		foreach ($this->fetchObjects($columns, $filter, $order, $limit, $offset) as $object)
		{
			$activities[] = $this->hydrate($object, $columns);
		}

		return $activities;
	}

	/**
	 * Identifiers of the activities the filter selects, in the order of the sort, from the offset on.
	 *
	 * THE ORDER IS WRITTEN HERE, NOT BY THE ORM. The ORM puts the NULL ordering of MySQL onto PostgreSQL for every
	 * column (`ASC NULLS FIRST`), and PostgreSQL walks no ascending index in that order: every page would sort the
	 * whole table, even by the primary key. A column that holds no NULL ({@see self::NOT_NULL_SORT_COLUMNS}) is
	 * ordered plainly, a nullable one keeps the NULL ordering of the ORM, so the order is the same either way. On
	 * MySQL the statement is the one the ORM builds.
	 *
	 * @return int[]
	 */
	public function getIds(ActivityFilter $filter, ActivitySort $sort, int $limit, int $offset): array
	{
		$query = ActivityTable::query()
			->setSelect(['ID'])
			->where($this->toStorageFilter($filter))
		;

		$connection = Application::getConnection();
		$order = $this->orderSql($query, $this->toStorageOrder($sort));
		$sql = $query->getQuery() . "\nORDER BY " . $order;
		if ($limit > 0)
		{
			$sql = $connection->getSqlHelper()->getTopSql($sql, $limit, $offset);
		}

		return array_map('intval', array_column($connection->query($sql)->fetchAll(), 'ID'));
	}

	/**
	 * Identifiers of the activities of these providers bound to exactly this object by a row of bindings, whoever
	 * owns their rows: one statement, in the order of the identifiers. A caller that reads them in portions names
	 * the last identifier it has got and how many it takes; fewer than that many means there are no more.
	 *
	 * THE ORDER IS BY AN EXPRESSION, NOT BY THE COLUMN. Asked for a few rows in the order of the primary key, a
	 * storage walks that key instead of sorting, and for a parent with fewer candidates than the limit the walk
	 * goes through every activity of the portal. An expression is no order of an index, so the candidates are
	 * found by the bindings and the providers as without a limit, and then sorted.
	 *
	 * @param string[] $providerIds values of the PROVIDER_ID column
	 * @param int $afterId only the identifiers above this one
	 * @param int $limit how many identifiers at most, 0 for all of them
	 * @return int[]
	 */
	public function getCandidateIds(
		ActivityBinding $binding,
		array $providerIds,
		int $afterId = 0,
		int $limit = 0,
	): array
	{
		$providerIds = array_values(array_unique(array_map('strval', $providerIds)));
		if ($providerIds === [])
		{
			return [];
		}

		$query = ActivityTable::query()
			->setSelect(['ID'])
			->whereIn('ID', $this->boundActivityIdsQuery($binding))
			->whereIn('PROVIDER_ID', $providerIds)
		;
		if ($afterId > 0)
		{
			$query->where('ID', '>', $afterId);
		}

		$connection = Application::getConnection();
		$helper = $connection->getSqlHelper();
		$column = $helper->quote($query->getInitAlias()) . '.' . $helper->quote('ID');
		$sql = $query->getQuery() . "\nORDER BY " . $column . ' + 0 ASC';
		if ($limit > 0)
		{
			$sql = $helper->getTopSql($sql, $limit);
		}

		return array_map('intval', array_column($connection->query($sql)->fetchAll(), 'ID'));
	}

	public function getOwners(array $ids): array
	{
		$ids = $this->normalizeIds($ids);
		if ($ids === [])
		{
			return [];
		}

		$columns = ['ID', self::OWNER_ID_COLUMN, self::OWNER_TYPE_ID_COLUMN];

		$owners = [];
		foreach ($this->fetchObjects($columns, (new ConditionTree())->whereIn('ID', $ids)) as $object)
		{
			$ownerId = (int)$object->get(self::OWNER_ID_COLUMN);
			$ownerTypeId = (int)$object->get(self::OWNER_TYPE_ID_COLUMN);
			if ($ownerId <= 0 || $ownerTypeId <= 0)
			{
				continue;
			}

			$owners[(int)$object->get('ID')] = new ActivityBinding(OwnerType::fromId($ownerTypeId), $ownerId);
		}

		return $owners;
	}

	public function getNotify(int $id): ?array
	{
		$object = $this->fetchOne(['ID', 'NOTIFY_TYPE', 'NOTIFY_VALUE'], $id);
		if ($object === null)
		{
			return null;
		}

		return [
			'type' => $this->toNullableInt($object->get('NOTIFY_TYPE')),
			'value' => $this->toNullableInt($object->get('NOTIFY_VALUE')),
		];
	}

	public function getThreadId(int $id): ?int
	{
		$object = $this->fetchOne(['ID', 'THREAD_ID'], $id);

		return $object === null ? null : (int)$object->get('THREAD_ID');
	}

	public function getProviderPayload(int $id): ?ProviderPayload
	{
		$settingsColumn = ActivityFieldMap::getPayloadColumn(ProviderPayload::settings);

		$columns = [];
		foreach (ProviderPayload::getSupportedFields() as $field)
		{
			$column = ActivityFieldMap::getPayloadColumn($field);
			$columns[$field] = $column === $settingsColumn ? self::RAW_SETTINGS_ALIAS : $column;
		}

		// Read as a row and not as an object: the unpacking callbacks of the field map hand an array back,
		// and an object would then cast it through the type of its text column - into the word "Array".
		// The expression carries `SETTINGS` past those callbacks - it has none of its own - so the column
		// arrives as it is stored; see the note of the class.
		$row = ActivityTable::getList([
			'runtime' => [new ExpressionField(self::RAW_SETTINGS_ALIAS, '%s', [$settingsColumn])],
			'select' => array_values($columns),
			'filter' => ['=ID' => $id],
		])->fetch();

		if (!is_array($row))
		{
			return null;
		}

		$payload = new ProviderPayload();
		foreach ($columns as $field => $column)
		{
			$value = $row[$column] ?? null;
			$payload->set(
				$field,
				$column === self::RAW_SETTINGS_ALIAS ? self::unserializeSettings($value) : $value,
			);
		}

		return $payload;
	}

	public function startOwnThread(int $activityId): void
	{
		if ($activityId <= 0)
		{
			throw new ArgumentException('An activity that has no id cannot start a chain of its own');
		}

		$result = ActivityTable::update($activityId, ['THREAD_ID' => $activityId]);
		if (!$result->isSuccess())
		{
			throw new ArgumentException(
				"Could not start the chain of activity #{$activityId}: "
				. implode('; ', $result->getErrorMessages()),
			);
		}
	}

	public function mergeThreadInto(int $activityId, int $threadId): void
	{
		if ($activityId <= 0)
		{
			throw new ArgumentException('An activity that has no id heads no chain');
		}

		// Written past the ORM the way legacy writes it (crm_activity.php:676-679): the set is named by a column
		// instead of by identifiers, and none of its rows is being edited as an activity - moving a chain changes
		// nothing anybody edited, so no audit stamp of it is bumped.
		$connection = Application::getConnection();
		$helper = $connection->getSqlHelper();
		$column = $helper->quote('THREAD_ID');

		$connection->queryExecute(
			'UPDATE ' . $helper->quote(ActivityTable::getTableName())
			. ' SET ' . $column . ' = ' . $threadId
			. ' WHERE ' . $column . ' = ' . $activityId,
		);
	}

	public function add(
		Activity $activity,
		?ProviderPayload $payload = null,
		?StorageFieldTransit $transit = null,
	): int
	{
		$columns = array_merge(
			$this->buildAddColumns($activity, $transit),
			$this->projectPayload($payload),
		);

		return $this->asOneWrite($payload, function () use ($columns, $payload): int {
			$result = ActivityTable::add($columns);
			if (!$result->isSuccess())
			{
				throw new ArgumentException(
					'Could not store the activity: ' . implode('; ', $result->getErrorMessages()),
				);
			}

			$id = (int)$result->getId();
			$this->writeSettings($id, $payload);

			return $id;
		});
	}

	public function syncOwnerColumns(
		int $activityId,
		array $bindings,
		?StorageFieldTransit $transit = null,
	): void
	{
		if ($activityId <= 0)
		{
			throw new ArgumentException('An activity that has no id cannot have its owner synchronized');
		}

		$result = ActivityTable::update(
			$activityId,
			$this->ownerColumnsOf($bindings, $transit, false),
		);
		if (!$result->isSuccess())
		{
			throw new ArgumentException(
				"Could not synchronize the owner of activity #{$activityId}: "
				. implode('; ', $result->getErrorMessages()),
			);
		}
	}

	public function update(
		Activity $activity,
		?ProviderPayload $payload = null,
		?StorageFieldTransit $transit = null,
	): void
	{
		$id = (int)$activity->getId();
		if ($id <= 0)
		{
			throw new ArgumentException('An activity that has no id cannot be updated');
		}

		$columns = array_merge(
			$this->buildUpdateColumns($activity, $id, $transit),
			$this->projectPayload($payload),
		);

		// Every update stamps the audit column, whatever else the row gets: legacy puts the current time into
		// `~LAST_UPDATED` on any update it runs (crm_activity.php:2392), and the incremental synchronizations
		// and the repair agents read that stamp. An operation that changed nothing else still leaves it.
		$columns['LAST_UPDATED'] = new DateTime();

		$this->asOneWrite($payload, function () use ($id, $columns, $payload): void {
			$result = ActivityTable::update($id, $columns);
			if (!$result->isSuccess())
			{
				throw new ArgumentException(
					"Could not store activity #{$id}: " . implode('; ', $result->getErrorMessages()),
				);
			}

			$this->writeSettings($id, $payload);
		});
	}

	/**
	 * Erases the row of the activity. Nothing else goes with it: the rows of the bindings, of the
	 * communications and of the files belong to their own writers, and the pipeline of a delete drops them
	 * through those before it gets here.
	 *
	 * THE STATEMENT IS A PLAIN ONE AND NOT A WRITE THROUGH THE ORM, because legacy erases the row with a
	 * plain statement of its own (`InnerDelete`, :1574) and the events of the ORM entity of an activity have
	 * never fired for a deletion. Going through {@see ActivityTable::delete()} would start firing them, which
	 * is a change of behaviour and not a change of style.
	 *
	 * The method is deliberately absent from {@see ActivityRepositoryInterface}: the one caller is the step
	 * erasing the row, and widening the boundary would oblige every test double of it to answer a question
	 * nobody asks.
	 *
	 * @throws ArgumentException on a non-positive id.
	 */
	public function deleteById(int $id): void
	{
		if ($id <= 0)
		{
			throw new ArgumentException('An activity that has no id cannot be deleted');
		}

		$connection = Application::getConnection();
		$helper = $connection->getSqlHelper();

		$connection->queryExecute(
			'DELETE FROM ' . $helper->quote(ActivityTable::getTableName())
			. ' WHERE ' . $helper->quote('ID') . ' = ' . $id,
		);
	}

	//region projection onto the columns

	/**
	 * @return array<string, mixed>
	 */
	private function buildAddColumns(Activity $activity, ?StorageFieldTransit $transit): array
	{
		$this->assertStorageRequiredFields($activity);

		$columns = $this->projectModelColumns($activity, false);

		$createdTime = $activity->getCreatedTime() ?? new DateTime();
		$columns['CREATED'] = $createdTime;
		$columns['LAST_UPDATED'] = $createdTime;

		// The column has no NULL of its own: a new row nobody handed a deadline over for carries the sentinel
		// of "none", which is what legacy leaves in its raw channel (:2170-2178). Deriving a deadline is the
		// business of the check of the fields, where legacy derives it too, and it arrives with the transit
		// below; a deadline of the caller never gets here at all - the model holds no way to write one.
		$columns['DEADLINE'] = ActivityFieldMap::getDeadlineSentinel();

		$isCompleted = $activity->isCompleted() ?? false;
		$columns['COMPLETED'] = $isCompleted;
		$columns['STATUS'] = ActivityFieldMap::toColumnValue(
			'STATUS',
			$activity->getCompletionStatus() ?? $this->statusOfCompletion($isCompleted),
		);
		$columns['IS_HANDLEABLE'] = !$isCompleted;

		// Columns the table declares as not nullable and the model cannot speak about: an activity of V2
		// is always a provider one, which is what legacy writes when a provider is given (:1904).
		$columns['TYPE_ID'] = CCrmActivityType::Provider;
		$columns['DIRECTION'] = ActivityFieldMap::toColumnValue('DIRECTION', $activity->getDirection());
		$columns['PRIORITY'] = ActivityFieldMap::toColumnValue('PRIORITY', $activity->isImportant());
		$columns['NOTIFY_TYPE'] = CCrmActivityNotifyType::None;
		// The list of files of a new activity is serialized even when it is empty, exactly as legacy writes it
		// (:121-126): the readers unserialize the column and treat a falsy value as "nobody said", so a row
		// left without it hands a null to callers typed for an array.
		$columns['STORAGE_ELEMENT_IDS'] = serialize([]);

		// The transit goes last: a value handed over beats the one derived here - the three grades of the
		// priority, an explicitly given owner, the handleable flag and the deadline depend on it.
		return array_merge(
			$columns,
			$this->projectTransit($transit, true),
			$this->ownerColumnsOf($activity->getBindings(), $transit, true),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function buildUpdateColumns(Activity $activity, int $id, ?StorageFieldTransit $transit): array
	{
		$columns = $this->projectModelColumns($activity, true);

		if ($activity->isFieldChanged(Activity::isImportant))
		{
			$columns['PRIORITY'] = ActivityFieldMap::toColumnValue('PRIORITY', $activity->isImportant());
		}

		if ($activity->isFieldChanged(Activity::isCompleted))
		{
			$isCompleted = (bool)$activity->isCompleted();
			$columns['STATUS'] = ActivityFieldMap::toColumnValue(
				'STATUS',
				$this->resolveCompletionStatus($activity, $id, $isCompleted),
			);
			$columns['IS_HANDLEABLE'] = !$isCompleted;
		}
		elseif ($activity->isFieldChanged(Activity::completionStatus))
		{
			$columns['STATUS'] = ActivityFieldMap::toColumnValue('STATUS', $activity->getCompletionStatus());
		}

		$ownerColumns = $this->shouldResolveOwner($activity, $transit)
			? $this->ownerColumnsOf($activity->getBindings(), $transit, false)
			: []
		;

		return array_merge($columns, $this->projectTransit($transit, false), $ownerColumns);
	}

	/**
	 * Core columns whose value comes straight from the model: everything on an add, only what the caller
	 * touched on an update.
	 *
	 * @return array<string, mixed>
	 */
	private function projectModelColumns(Activity $activity, bool $onlyChanged): array
	{
		$columns = [];
		foreach (ActivityFieldMap::getCoreColumns() as $column)
		{
			if (in_array($column, self::DERIVED_COLUMNS, true))
			{
				continue;
			}
			if ($onlyChanged && in_array($column, self::IMMUTABLE_ON_UPDATE_COLUMNS, true))
			{
				continue;
			}

			$fieldName = (string)ActivityFieldMap::getFieldName($column);
			if ($onlyChanged && !$activity->isFieldChanged($fieldName))
			{
				continue;
			}

			$value = $this->readField($activity, $fieldName);
			if (!$onlyChanged && $value === null)
			{
				continue;
			}

			$columns[$column] = ActivityFieldMap::toColumnValue($column, $value);
		}

		return $columns;
	}

	/**
	 * Values of the storage columns the legacy input carries, as they were handed over. The owner pair is
	 * left out: it is resolved together with the bindings.
	 *
	 * A COLUMN HANDED OVER AS NULL IS NOT WRITTEN, which is the same rule the columns of the model follow above
	 * and the rule of the legacy write itself: it assembles its row with `is_set()` and passes over a key whose
	 * value is null. The distinction matters because callers really do hand one over - the domain entity of a
	 * to-do names the calendar event of an activity that has none - and the columns of this table are not
	 * nullable, so writing it refuses the whole row. Whether the operation NAMED the column is a question of the
	 * steps and they ask the transit itself, so nothing above this point is affected.
	 *
	 * @return array<string, mixed>
	 */
	private function projectTransit(?StorageFieldTransit $transit, bool $isAdd): array
	{
		if ($transit === null)
		{
			return [];
		}

		$columns = array_filter($transit->getValues(), static fn(mixed $value): bool => $value !== null);
		unset($columns[self::OWNER_ID_COLUMN], $columns[self::OWNER_TYPE_ID_COLUMN]);

		if (!$isAdd)
		{
			// Legacy never changes the type of an existing activity (:2124).
			unset($columns['TYPE_ID']);
		}

		return $columns;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function projectPayload(?ProviderPayload $payload): array
	{
		if ($payload === null)
		{
			return [];
		}

		$columns = [];
		foreach (ProviderPayload::getSupportedFields() as $field)
		{
			if ($field === ProviderPayload::settings || !$payload->has($field))
			{
				continue;
			}

			$columns[ActivityFieldMap::getPayloadColumn($field)] = $payload->get($field);
		}

		return $columns;
	}

	//endregion

	//region derived values

	private function statusOfCompletion(bool $isCompleted): CompletionStatus
	{
		return $isCompleted ? CompletionStatus::Completed : CompletionStatus::Waiting;
	}

	/**
	 * The status of an activity whose completion flag is being written, by the rules of legacy
	 * (:2059-2084): "not completed" forces the waiting state whatever the caller asked for, a completion
	 * keeps an earlier automatic one, and the waiting state cannot survive a completion.
	 */
	private function resolveCompletionStatus(Activity $activity, int $id, bool $isCompleted): CompletionStatus
	{
		if (!$isCompleted)
		{
			return CompletionStatus::Waiting;
		}

		$givenStatus = $activity->isFieldChanged(Activity::completionStatus)
			? $activity->getCompletionStatus()
			: null
		;

		if ($givenStatus === null)
		{
			return $this->readCompletionStatus($id) === CompletionStatus::AutoCompleted
				? CompletionStatus::AutoCompleted
				: CompletionStatus::Completed
			;
		}

		return $givenStatus === CompletionStatus::Waiting ? CompletionStatus::Completed : $givenStatus;
	}

	private function readCompletionStatus(int $id): CompletionStatus
	{
		$object = $this->fetchOne(['ID', 'STATUS'], $id);

		return $object === null
			? CompletionStatus::Undefined
			: ActivityFieldMap::toFieldValue('STATUS', $object->get('STATUS'))
		;
	}

	private function shouldResolveOwner(Activity $activity, ?StorageFieldTransit $transit): bool
	{
		return $activity->isFieldChanged(Activity::bindings)
			|| $this->hasTransitValue($transit, self::OWNER_ID_COLUMN)
			|| $this->hasTransitValue($transit, self::OWNER_TYPE_ID_COLUMN)
		;
	}

	/**
	 * THE ONE ANSWER OF THE STORAGE LAYER TO "WHO OWNS THIS ACTIVITY": an owner the operation itself named
	 * wins, and the bindings decide only where it named none. Every writer of the two columns goes through
	 * here - the insert, the update and the synchronization the repository of the bindings asks for - so
	 * that a write of the set cannot undo what the same operation said about the owner a moment earlier.
	 *
	 * @param ActivityBinding[]|null $bindings the set of the operation; null is "it named none at all".
	 * @return array<string, int>
	 */
	private function ownerColumnsOf(
		?array $bindings,
		?StorageFieldTransit $transit,
		bool $isAdd,
	): array
	{
		$ownerId = $this->readTransitInt($transit, self::OWNER_ID_COLUMN);
		$ownerTypeId = $this->readTransitInt($transit, self::OWNER_TYPE_ID_COLUMN);
		if ($ownerId > 0 && $ownerTypeId > 0)
		{
			return [
				self::OWNER_ID_COLUMN => $ownerId,
				self::OWNER_TYPE_ID_COLUMN => $ownerTypeId,
			];
		}

		if ($bindings === null)
		{
			// Nothing to derive from: on an add the columns are not nullable, on an update they stay.
			return $isAdd
				? [
					self::OWNER_ID_COLUMN => $ownerId,
					self::OWNER_TYPE_ID_COLUMN => $ownerTypeId,
				]
				: []
			;
		}

		return $this->ownerColumns($this->deriveOwnerFromBindings($bindings));
	}

	/**
	 * @return array<string, int> the pair of owner columns; a missing owner is the zero of both.
	 */
	private function ownerColumns(?ActivityBinding $owner): array
	{
		return [
			self::OWNER_ID_COLUMN => $owner?->getOwnerId() ?? 0,
			self::OWNER_TYPE_ID_COLUMN => $owner?->getOwnerType()->getId() ?? OwnerType::UNDEFINED,
		];
	}

	/**
	 * The binding that becomes the owner of the activity, by the rules of legacy
	 * (`appendOwnerFromBindings`, :1221-1245): a binding that is neither a contact nor a company wins and
	 * the loop does not stop, so the last such binding is the one that stays; with only clients bound,
	 * the first binding is taken.
	 *
	 * @param ActivityBinding[] $bindings
	 */
	private function deriveOwnerFromBindings(array $bindings): ?ActivityBinding
	{
		$owner = null;
		foreach ($bindings as $binding)
		{
			$ownerTypeId = $binding->getOwnerType()->getId();
			if ($ownerTypeId !== OwnerType::CONTACT && $ownerTypeId !== OwnerType::COMPANY)
			{
				$owner = $binding;
			}
		}

		if ($owner !== null)
		{
			return $owner;
		}

		$first = reset($bindings);

		return $first === false ? null : $first;
	}

	//endregion

	//region reading

	/**
	 * @param string[] $columns
	 * @return EntityObject[]
	 */
	private function fetchObjects(
		array $columns,
		array|ConditionTree|null $filter = null,
		?array $order = null,
		?int $limit = null,
		?int $offset = null,
	): array
	{
		$query = ActivityTable::query()->setSelect($columns);

		if ($filter instanceof ConditionTree)
		{
			$query->where($filter);
		}
		elseif (!empty($filter))
		{
			$query->setFilter($filter);
		}

		if (!empty($order))
		{
			$query->setOrder($order);
		}
		if ($limit !== null)
		{
			$query->setLimit($limit);
		}
		if ($offset !== null)
		{
			$query->setOffset($offset);
		}

		return $query->fetchCollection()->getAll();
	}

	private function toStorageFilter(ActivityFilter $filter): ConditionTree
	{
		$storageFilter = (new ConditionTree())->where($this->toStorageTree($filter->getConditions()));

		$binding = $filter->getBinding();
		if ($binding !== null)
		{
			$storageFilter->whereIn('ID', $this->boundActivityIdsQuery($binding));
		}

		$excludedIds = $filter->getExcludedIds();
		if ($excludedIds !== [])
		{
			$storageFilter->whereNotIn('ID', $excludedIds);
		}

		return $storageFilter;
	}

	/** The identifiers of the activities bound to exactly this object by a row of bindings. */
	private function boundActivityIdsQuery(ActivityBinding $binding): Query
	{
		return ActivityBindingTable::query()
			->setSelect(['ACTIVITY_ID'])
			->where('OWNER_TYPE_ID', $binding->getOwnerType()->getId())
			->where('OWNER_ID', $binding->getOwnerId())
		;
	}

	/**
	 * A copy keeps the logic and the negation of the group, which the tree does not let read.
	 */
	private function toStorageTree(ConditionTree $tree): ConditionTree
	{
		$storageTree = clone $tree;
		$storageTree->removeAllConditions();
		foreach ($tree->getConditions() as $condition)
		{
			$storageTree->addCondition(
				$condition instanceof ConditionTree
					? $this->toStorageTree($condition)
					: $this->toStorageCondition($condition)
			);
		}

		return $storageTree;
	}

	private function toStorageCondition(Condition $condition): Condition|ConditionTree
	{
		$column = ActivityFieldMap::getReadColumn((string)$condition->getColumn());
		if ($column === null)
		{
			throw new ArgumentException("'{$condition->getColumn()}' is not stored in the table of activities");
		}

		$operator = $condition->getOperator();
		$value = $condition->getValue();
		if ($column === 'DEADLINE')
		{
			return $this->toStorageDeadlineCondition($operator, $value);
		}

		if ($value !== null)
		{
			$value = is_array($value)
				? array_map(static fn($item) => ActivityFieldMap::toReadColumnValue($column, $item), $value)
				: ActivityFieldMap::toReadColumnValue($column, $value)
			;
		}

		return new Condition($column, $operator, $value);
	}

	/**
	 * The column says "no deadline" both with NULL and with the far date of {@see ActivityFieldMap}, and the model
	 * reads either as none: a date compared with the deadline never matches the far one, and a missing deadline
	 * matches both.
	 *
	 * A DATE IS NEVER UNKNOWN FOR NULL. The comparison is FALSE on a NULL row rather than NULL, so a negated group
	 * keeps a row without a deadline whichever of the two answers the row stores.
	 */
	private function toStorageDeadlineCondition(string $operator, mixed $value): ConditionTree
	{
		$sentinelYear = ActivityFieldMap::getDeadlineSentinel()->format('Y');
		$noDeadlineFrom = new DateTime("{$sentinelYear}-01-01 00:00:00", 'Y-m-d H:i:s');

		if ($value === null)
		{
			return $operator === '='
				? (new ConditionTree())->logic(ConditionTree::LOGIC_OR)
					->whereNull('DEADLINE')
					->where('DEADLINE', '>=', $noDeadlineFrom)
				: (new ConditionTree())->whereNotNull('DEADLINE')->where('DEADLINE', '<', $noDeadlineFrom)
			;
		}

		return (new ConditionTree())
			->whereNotNull('DEADLINE')
			->where('DEADLINE', $operator, $value)
			->where('DEADLINE', '<', $noDeadlineFrom)
		;
	}

	/**
	 * @return array<string, string>
	 */
	private function toStorageOrder(ActivitySort $sort): array
	{
		$order = [];
		foreach ($sort->getOrder() as $fieldName => $direction)
		{
			$order[(string)ActivityFieldMap::getReadColumn($fieldName)] = $direction->value;
		}

		return $order;
	}

	/**
	 * The ORDER BY of the statement of {@see self::getIds()}: a column of {@see self::NOT_NULL_SORT_COLUMNS} in its
	 * direction alone and a nullable one with the NULL ordering of the storage. `ID` is the column the statement
	 * selects and is named by its own name, as the ORM names it; every other column is named through the table.
	 *
	 * @param array<string, string> $order
	 */
	private function orderSql(Query $query, array $order): string
	{
		$helper = Application::getConnection()->getSqlHelper();
		$table = $helper->quote($query->getInitAlias());
		$parts = [];
		foreach ($order as $column => $direction)
		{
			$isAscending = strtoupper($direction) === 'ASC';
			if (in_array($column, self::NOT_NULL_SORT_COLUMNS, true))
			{
				$sqlDirection = $isAscending ? 'ASC' : 'DESC';
			}
			else
			{
				$sqlDirection = $isAscending ? $helper->getAscendingOrder() : $helper->getDescendingOrder();
			}
			$name = $helper->quote($column);
			$parts[] = ($column === 'ID' ? $name : $table . '.' . $name) . ' ' . $sqlDirection;
		}

		return implode(', ', $parts);
	}

	/**
	 * @param string[] $columns
	 */
	private function fetchOne(array $columns, int $id): ?EntityObject
	{
		$objects = $this->fetchObjects($columns, (new ConditionTree())->where('ID', $id));

		return $objects[0] ?? null;
	}

	/**
	 * @param string[] $columns
	 */
	private function hydrate(EntityObject $object, array $columns): Activity
	{
		$activity = new Activity();
		foreach ($columns as $column)
		{
			$fieldName = ActivityFieldMap::getFieldName($column);
			if ($fieldName === null)
			{
				continue;
			}

			$activity->internalSet($fieldName, ActivityFieldMap::toFieldValue($column, $object->get($column)));
		}

		return $activity;
	}

	/**
	 * The columns a stored state carries in its transit: the ones the transit accepts and the model does not
	 * hold. The two the model does hold in a poorer form - the deadline and the priority - are left out of a
	 * read on purpose, so that a state has one answer about each of them and it is the answer of the model.
	 *
	 * THE PRIORITY THE MODEL ANSWERS WITH IS A FLAG telling the high grade from the other three, so a state
	 * read here cannot say which of those three its column holds. That is a registered divergence from legacy
	 * and not an omission: see the row PRIORITY-HISTORY-BY-FLAG of the registry of intentional deviations and
	 * {@see \Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Update\WriteHistory::priorityText()}, the
	 * one reader the poorer answer is observable through.
	 *
	 * @return string[]
	 */
	private static function transitColumnsOfARow(): array
	{
		return array_values(array_filter(
			StorageFieldTransit::getSupportedFields(),
			static fn(string $column): bool => ActivityFieldMap::getFieldName($column) === null,
		));
	}

	/**
	 * @param string[]|null $fieldNames
	 * @return string[] columns to select, the primary always among them
	 * @throws ArgumentException on a field name that is not stored in the table.
	 */
	private function resolveSelect(?array $fieldNames): array
	{
		if ($fieldNames === null)
		{
			return ActivityFieldMap::getCoreColumns();
		}

		$columns = ['ID' => 'ID'];
		foreach ($fieldNames as $fieldName)
		{
			$column = ActivityFieldMap::getColumn((string)$fieldName);
			if ($column === null)
			{
				throw new ArgumentException("'{$fieldName}' is not stored in a column of an activity");
			}

			$columns[$column] = $column;
		}

		return array_values($columns);
	}

	//endregion

	//region reading and writing past the field map

	/**
	 * Unpacks `SETTINGS` the way the legacy reader leaves it - one plain unserialization, no decoding of the
	 * escaped emoji inside (`CCrmActivityDbResult::fetchOneRecord`). The unpacking callback of the field map
	 * decodes them, and a value read decoded and written back plain ({@see self::writeSettings()}) would
	 * rewrite an escaped column in the decoded shape.
	 */
	private static function unserializeSettings(mixed $rawValue): mixed
	{
		// The gate of the field of the entity, kept as it is: an empty column is an empty set.
		if (!is_string($rawValue) || $rawValue === '')
		{
			return [];
		}

		return unserialize($rawValue, ['allowed_classes' => false]);
	}

	/**
	 * Runs a write of the row that needs a second statement inside one transaction, and a write that needs
	 * only one statement without any: `SETTINGS` is the one column that cannot travel with the rest
	 * ({@see self::writeSettings()}), so a write carrying it is the only write of this boundary made of two
	 * statements, and the only one where a failure of the second would leave half a row behind - an activity
	 * without the settings of its provider on an add, an update answering success over a column that never
	 * got there. Legacy has no transaction because legacy needs none: it writes the column in the same
	 * statement as the rest.
	 *
	 * NESTING IS THE NORMAL CASE, not the exception, and both drivers of the portal express it with a
	 * savepoint (`MysqlCommonConnection::startTransaction()`, `PgsqlConnection::startTransaction()`): a write
	 * of an activity happens inside whatever transaction its caller opened, and a rollback here undoes this
	 * pair alone.
	 *
	 * @template T
	 * @param Closure(): T $write
	 * @return T
	 */
	private function asOneWrite(?ProviderPayload $payload, Closure $write): mixed
	{
		if ($payload === null || !$payload->has(ProviderPayload::settings))
		{
			return $write();
		}

		$connection = Application::getConnection();
		$connection->startTransaction();

		try
		{
			$written = $write();
		}
		catch (Throwable $failure)
		{
			$connection->rollbackTransaction();

			throw $failure;
		}

		$connection->commitTransaction();

		return $written;
	}

	/**
	 * Writes `SETTINGS` the way the legacy path leaves it - one plain serialization, no escaping of the
	 * strings inside. The serialization callback of the field map would escape them, so this column
	 * cannot travel with the rest of the write - which is why a write carrying it is wrapped in a
	 * transaction of its own ({@see self::asOneWrite()}).
	 */
	private function writeSettings(int $id, ?ProviderPayload $payload): void
	{
		if ($payload === null || !$payload->has(ProviderPayload::settings))
		{
			return;
		}

		$connection = Application::getConnection();
		$helper = $connection->getSqlHelper();
		$tableName = ActivityTable::getTableName();

		[$update] = $helper->prepareUpdate($tableName, [
			ActivityFieldMap::getPayloadColumn(ProviderPayload::settings) => serialize(
				$payload->get(ProviderPayload::settings),
			),
		]);

		$connection->queryExecute(
			'UPDATE ' . $helper->quote($tableName) . ' SET ' . $update
			. ' WHERE ' . $helper->quote('ID') . ' = ' . $id,
		);
	}

	//endregion

	//region helpers

	/**
	 * @throws ArgumentException when a field the columns cannot default is missing.
	 */
	private function assertStorageRequiredFields(Activity $activity): void
	{
		$missing = [];
		if ($activity->getProviderId() === null)
		{
			$missing[] = Activity::providerId;
		}
		if ($activity->getSubject() === null)
		{
			$missing[] = Activity::subject;
		}
		if ($activity->getResponsibleId() === null)
		{
			$missing[] = Activity::responsibleId;
		}

		if ($missing !== [])
		{
			throw new ArgumentException(
				'An activity cannot be stored without: ' . implode(', ', $missing),
			);
		}
	}

	private function readField(Activity $activity, string $fieldName): mixed
	{
		return match ($fieldName)
		{
			Activity::id => $activity->getId(),
			Activity::providerId => $activity->getProviderId(),
			Activity::providerTypeId => $activity->getProviderTypeId(),
			Activity::subject => $activity->getSubject(),
			Activity::description => $activity->getDescription(),
			Activity::descriptionType => $activity->getDescriptionType(),
			Activity::isCompleted => $activity->isCompleted(),
			Activity::completionStatus => $activity->getCompletionStatus(),
			Activity::responsibleId => $activity->getResponsibleId(),
			Activity::authorId => $activity->getAuthorId(),
			Activity::editorId => $activity->getEditorId(),
			Activity::isImportant => $activity->isImportant(),
			Activity::direction => $activity->getDirection(),
			Activity::createdTime => $activity->getCreatedTime(),
			Activity::updatedTime => $activity->getUpdatedTime(),
			Activity::startTime => $activity->getStartTime(),
			Activity::endTime => $activity->getEndTime(),
			Activity::deadline => $activity->getDeadline(),
			Activity::parentActivityId => $activity->getParentActivityId(),
			Activity::autocompleteRule => $activity->getAutocompleteRule(),
			default => throw new ArgumentException("'{$fieldName}' is not a stored field of an activity"),
		};
	}

	/**
	 * The ids the column lists, read the way legacy reads it (`CCrmActivity::extractStorageElementIds()`): one
	 * plain unserialization, the positive ids kept. A column holding anything but a serialized list holds no file.
	 *
	 * @return int[]
	 */
	private static function storageElementIdsOf(mixed $storedValue): array
	{
		if (!is_string($storedValue) || !str_starts_with($storedValue, 'a:'))
		{
			return [];
		}

		$ids = unserialize($storedValue, ['allowed_classes' => false]);
		if (!is_array($ids))
		{
			return [];
		}

		return array_values(array_filter(
			array_map('intval', $ids),
			static fn(int $id): bool => $id > 0,
		));
	}

	/**
	 * A stored column in the one shape the transit carries it in - the legacy one, which is what every other
	 * producer of a transit puts there and what legacy hands a provider in `PREVIOUS_FIELDS`. Two translations
	 * are needed. The handleable flag: its ORM field is a boolean over a column of 'N' and 'Y'
	 * (`crm/lib/activity.php`), so a read gives a `bool` where the rest of the pipeline speaks the letters.
	 * The text columns: their ORM fields are plain strings, so a NULL of the row - the origin of a planned
	 * activity - comes out of the ORM as '', while the raw fetch of legacy keeps the NULL, and a reader of the
	 * state handed on in the legacy shape tells "planned" by exactly that null (the item of a call in the
	 * timeline, Mantis 260654). The read projection makes the same translation ({@see ActivityFieldMap::toText()}).
	 */
	private static function transitValueOf(string $column, mixed $storedValue): mixed
	{
		if ($column === self::HANDLEABLE_COLUMN && is_bool($storedValue))
		{
			return $storedValue ? 'Y' : 'N';
		}

		if ($storedValue === '' && ActivityFieldMap::getType($column) === ActivityFieldMap::TYPE_STRING)
		{
			return null;
		}

		return $storedValue;
	}

	private function hasTransitValue(?StorageFieldTransit $transit, string $column): bool
	{
		return $transit !== null && $transit->has($column);
	}

	private function readTransitInt(?StorageFieldTransit $transit, string $column): int
	{
		return $this->hasTransitValue($transit, $column) ? (int)$transit->get($column) : 0;
	}

	private function toNullableInt(mixed $value): ?int
	{
		return $value === null ? null : (int)$value;
	}

	/**
	 * @param int[] $ids
	 * @return int[] positive ids, without repetitions
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

	//endregion
}
