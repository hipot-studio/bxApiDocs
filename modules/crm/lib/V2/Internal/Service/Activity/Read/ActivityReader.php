<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Read;

use Bitrix\Crm\ActivityTable;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\V2\Internal\Access\Activity\ActivityAccess;
use Bitrix\Crm\V2\Internal\Entity\Activity\Activity;
use Bitrix\Crm\V2\Internal\Entity\Activity\ValueObject\ActivityFile;
use Bitrix\Crm\V2\Internal\Integration\Disk\ActivityStorageElements;
use Bitrix\Crm\V2\Internal\Repository\Activity\ActivityBindingRepository;
use Bitrix\Crm\V2\Internal\Repository\Activity\ActivityCommunicationRepository;
use Bitrix\Crm\V2\Internal\Repository\Activity\ActivityFieldMap;
use Bitrix\Crm\V2\Internal\Repository\Activity\ActivityStorageElementRepository;
use Bitrix\Main\Error;
use Bitrix\Main\ORM\Fields\ScalarField;
use Bitrix\Main\Result;

/**
 * Reads one activity on behalf of one user: the row, the right to it, and then what the caller selected. A page
 * of activities whose right the caller has decided is assembled the same way with the queries of one
 * ({@see self::getManyUnchecked()}).
 *
 * An absent activity and a forbidden one leave through the same refusal, and both leave before anything but
 * the row and the right has been read. The right is asked even where no row answered. A read in a context
 * ({@see ActivityContext}) takes the bindings next and, where the context does not admit the activity, leaves
 * through the same refusal before anything else has been read. The bindings a read of one activity loads, in a
 * context or by the select, are the ones the right was decided on ({@see ActivityAccess::getBindings()}), so a
 * right granted through a binding costs no second read of them.
 *
 * The row is read in its read projection ({@see ActivityFieldMap::getReadColumns()}), since the repository of
 * the row hydrates only the columns a write addresses.
 *
 * It is fetched as an array rather than as an object of the ORM, whose cast is wrong for a read: a NULL of a
 * column the ORM does not declare nullable becomes the zero or the empty string of its type. Values the
 * database hands over as strings are typed by the same cast the object would apply ({@see self::storedValueOf()}).
 *
 * The select decides what is loaded. An empty one means the collections, the incoming-channel sign and the text
 * of a compressed mail ({@see ActivityMailBodyLoader}); the columns of the read projection are read always.
 * What is not loaded stays null on the model, which a loaded but empty collection is not.
 *
 * Nothing is written: every value lands on the model through its hydration path, which marks no field as
 * changed.
 *
 * @internal
 */
final class ActivityReader
{
	/** Result data key: the {@see Activity} read. */
	public const DATA_ACTIVITY = 'activity';

	/** Result data key: the {@see ActivityFile} metadata of its files, null where the select left them out. */
	public const DATA_FILES = 'files';

	/** The one error of a refusal, whether the activity is absent or forbidden. */
	public const ERROR_NOT_FOUND = 'ACTIVITY_NOT_FOUND';

	private const MESSAGE_NOT_FOUND = 'Activity not found';

	/** What a read loads when the select names nothing. */
	private const LOADED_BY_DEFAULT = [
		Activity::description,
		Activity::bindings,
		Activity::communications,
		Activity::files,
		Activity::isIncomingChannel,
	];

	/** The storage the files of the activity live in, a column of the row no model holds. */
	private const STORAGE_TYPE_COLUMN = 'STORAGE_TYPE_ID';

	private ?ActivityAccess $access = null;

	private ?ActivityBindingRepository $bindingRepository = null;

	private ?ActivityCommunicationRepository $communicationRepository = null;

	private ?ActivityStorageElementRepository $storageElementRepository = null;

	private ?ActivityStorageElements $storageElements = null;

	private ?ActivityIncomingChannelLoader $incomingChannelLoader = null;

	private ?ActivityCommunicationMasker $communicationMasker = null;

	private ?ActivityMailBodyLoader $mailBodyLoader = null;

	/**
	 * @param string[] $select names of the fields the caller answers; an empty one means the set a read answers
	 *        by default. The names are the published ones, which for everything loaded on demand are the names
	 *        of the model as well. A name nothing here loads is passed over.
	 * @param ActivityContext|null $context the rule the activity must pass after the right; its bindings are then
	 *        loaded whatever the select, and the caller takes off the ones it did not select.
	 * @return Result success with {@see self::DATA_ACTIVITY} and {@see self::DATA_FILES}, or exactly one
	 *         {@see self::ERROR_NOT_FOUND} for an activity that is absent, that the user may not read or that the
	 *         context does not admit.
	 */
	public function get(int $userId, int $activityId, array $select = [], ?ActivityContext $context = null): Result
	{
		$selected = array_fill_keys($select === [] ? self::LOADED_BY_DEFAULT : $select, true);
		$columns = self::columnsFor($selected);

		$row = $activityId > 0 ? self::readRow($activityId, $columns) : null;
		$isReadable = $userId > 0 && $this->getAccess()->canRead($userId, $activityId);
		if ($row === null || !$isReadable)
		{
			return self::notFound();
		}

		$activity = self::hydrate($row, $columns);
		if ($context !== null || isset($selected[Activity::bindings]))
		{
			$this->loadBindings($activity);
		}

		if ($context !== null && !$context->admits($activity))
		{
			return self::notFound();
		}

		$files = $this->enrich($userId, [$activityId => $activity], [$activityId => $row], $selected);

		return (new Result())->setData([
			self::DATA_ACTIVITY => $activity,
			self::DATA_FILES => $files[$activityId] ?? null,
		]);
	}

	/**
	 * A page of activities WITHOUT ANY CHECK OF THE RIGHT TO THEM: the caller has decided the user may read them
	 * all. The rows and everything the select names are read for the whole set at once, so the queries do not grow
	 * with the number of the activities, and each activity is assembled as {@see get()} assembles it. One that is
	 * absent is left out.
	 *
	 * @param int[] $activityIds
	 * @param string[] $select as {@see get()} takes it
	 * @return array<int, array{activity: Activity, files: ActivityFile[]|null}> in the order of the given ids
	 */
	public function getManyUnchecked(int $userId, array $activityIds, array $select = []): array
	{
		$activityIds = array_values(array_unique(array_filter($activityIds, static fn(int $id): bool => $id > 0)));
		if ($userId <= 0 || $activityIds === [])
		{
			return [];
		}

		$selected = array_fill_keys($select === [] ? self::LOADED_BY_DEFAULT : $select, true);
		$columns = self::columnsFor($selected);
		$rows = self::readRows($activityIds, $columns);

		$activities = [];
		foreach ($rows as $activityId => $row)
		{
			$activities[$activityId] = self::hydrate($row, $columns);
		}
		$files = $this->enrich($userId, $activities, $rows, $selected);

		$items = [];
		foreach ($activities as $activityId => $activity)
		{
			$items[] = [
				self::DATA_ACTIVITY => $activity,
				self::DATA_FILES => $files[$activityId] ?? null,
			];
		}

		return $items;
	}

	/**
	 * Loads what the select names for the whole set, one query per collection. The communications are masked as
	 * soon as they are read, so an address the user may not see never reaches the model it is handed. Bindings
	 * an activity already carries, the ones a single read took from the check of the right, are not read again.
	 *
	 * @param array<int, Activity> $activities by activity id
	 * @param array<int, array<string, mixed>> $rows by activity id
	 * @param array<string, true> $selected
	 * @return array<int, ActivityFile[]>|null the metadata of the files by activity id, null where they were not
	 *         selected.
	 */
	private function enrich(int $userId, array $activities, array $rows, array $selected): ?array
	{
		if ($activities === [])
		{
			return null;
		}

		$activityIds = array_keys($activities);

		if (isset($selected[Activity::description]))
		{
			$this->getMailBodyLoader()->fill(...array_values($activities));
		}

		if (isset($selected[Activity::bindings]))
		{
			$unboundIds = array_keys(
				array_filter($activities, static fn(Activity $activity): bool => $activity->getBindings() === null)
			);
			$bindings = $unboundIds === [] ? [] : $this->getBindingRepository()->getByActivityIds($unboundIds);
			foreach ($unboundIds as $activityId)
			{
				$activities[$activityId]->internalSet(Activity::bindings, $bindings[$activityId] ?? []);
			}
		}

		if (isset($selected[Activity::communications]))
		{
			$communications = $this->getCommunicationRepository()->getByActivityIds($activityIds);
			foreach ($activities as $activityId => $activity)
			{
				$activity->internalSet(Activity::communications, $communications[$activityId] ?? []);
			}
			$this->getCommunicationMasker()->mask($userId, ...array_values($activities));
		}

		if (isset($selected[Activity::isIncomingChannel]))
		{
			$this->getIncomingChannelLoader()->fill(...array_values($activities));
		}

		if (!isset($selected[Activity::files]))
		{
			return null;
		}

		$storageTypeIds = [];
		foreach ($activityIds as $activityId)
		{
			$storageTypeIds[$activityId] = (int)($rows[$activityId][self::STORAGE_TYPE_COLUMN] ?? 0);
		}

		return $this->getStorageElements()->getFilesOfActivities(
			$storageTypeIds,
			$this->getStorageElementRepository()->getElementIdsByActivityIds($activityIds),
		);
	}

	private function loadBindings(Activity $activity): void
	{
		$activity->internalSet(Activity::bindings, $this->getAccess()->getBindings((int)$activity->getId()));
	}

	private static function notFound(): Result
	{
		return (new Result())->addError(new Error(self::MESSAGE_NOT_FOUND, self::ERROR_NOT_FOUND));
	}

	/**
	 * @param array<string, true> $selected
	 * @return string[]
	 */
	private static function columnsFor(array $selected): array
	{
		$columns = ActivityFieldMap::getReadColumns();

		if (isset($selected[Activity::files]))
		{
			$columns[] = self::STORAGE_TYPE_COLUMN;
		}

		return $columns;
	}

	/**
	 * @param string[] $columns
	 * @return array<string, mixed>|null
	 */
	private static function readRow(int $activityId, array $columns): ?array
	{
		$row =
			ActivityTable::query()
				->setSelect($columns)
				->where('ID', $activityId)
				->fetch()
		;

		return is_array($row) ? $row : null;
	}

	/**
	 * @param int[] $activityIds
	 * @param string[] $columns
	 * @return array<int, array<string, mixed>> by activity id, in the order of the given ids
	 */
	private static function readRows(array $activityIds, array $columns): array
	{
		if ($activityIds === [])
		{
			return [];
		}

		$fetched = [];
		$query =
			ActivityTable::query()
				->setSelect(array_values(array_unique([...$columns, 'ID'])))
				->whereIn('ID', $activityIds)
				->exec()
		;
		while ($row = $query->fetch())
		{
			$fetched[(int)$row['ID']] = $row;
		}

		$rows = [];
		foreach ($activityIds as $activityId)
		{
			if (isset($fetched[$activityId]))
			{
				$rows[$activityId] = $fetched[$activityId];
			}
		}

		return $rows;
	}

	/**
	 * @param array<string, mixed> $row
	 * @param string[] $columns
	 */
	private static function hydrate(array $row, array $columns): Activity
	{
		$activity = new Activity();
		foreach ($columns as $column)
		{
			$fieldName = ActivityFieldMap::getReadFieldName($column);
			if ($fieldName !== null)
			{
				$storedValue = self::storedValueOf($column, $row[$column] ?? null);
				$activity->internalSet($fieldName, ActivityFieldMap::toReadFieldValue($column, $storedValue));
			}
		}

		return $activity;
	}

	/**
	 * Types a value the database handed over as a string the way the object of the ORM would. A NULL and a
	 * value the fetch has already unpacked or built stay what they are.
	 */
	private static function storedValueOf(string $column, mixed $value): mixed
	{
		if (!is_string($value))
		{
			return $value;
		}

		$field = ActivityTable::getEntity()->getField($column);

		return $field instanceof ScalarField ? $field->cast($value) : $value;
	}

	/** The access model of the hit, so this check matches the one every other scenario of the hit makes. */
	private function getAccess(): ActivityAccess
	{
		return $this->access ??= Container::getInstance()->getActivityAccess();
	}

	private function getBindingRepository(): ActivityBindingRepository
	{
		return $this->bindingRepository ??= new ActivityBindingRepository();
	}

	private function getCommunicationRepository(): ActivityCommunicationRepository
	{
		return $this->communicationRepository ??= new ActivityCommunicationRepository();
	}

	private function getStorageElementRepository(): ActivityStorageElementRepository
	{
		return $this->storageElementRepository ??= new ActivityStorageElementRepository();
	}

	private function getStorageElements(): ActivityStorageElements
	{
		return $this->storageElements ??= new ActivityStorageElements();
	}

	private function getIncomingChannelLoader(): ActivityIncomingChannelLoader
	{
		return $this->incomingChannelLoader ??= new ActivityIncomingChannelLoader();
	}

	private function getCommunicationMasker(): ActivityCommunicationMasker
	{
		return $this->communicationMasker ??= new ActivityCommunicationMasker();
	}

	private function getMailBodyLoader(): ActivityMailBodyLoader
	{
		return $this->mailBodyLoader ??= new ActivityMailBodyLoader();
	}
}
