<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Sync\Check;

use Bitrix\Disk\Driver;
use Bitrix\Disk\Internal\Service\Sync\Snapshot\AccessSourceConfirmerInterface;
use Bitrix\Disk\Internal\Service\Sync\SyncLimits;
use Bitrix\Disk\Internal\Service\Sync\Visibility\AccessSourceConfirmer;
use Bitrix\Disk\Internals\ObjectTable;
use Bitrix\Disk\Internals\TrackedObjectTable;
use Bitrix\Disk\Security\DiskSecurityContext;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\DateTime;

/**
 * Which of the objects a client names itself it may still see.
 *
 * The check exists for what the journal cannot answer: memory brought back together after a cursor
 * went stale, and objects whose place cannot be matched against the root of a revocation at all. It
 * answers about the state of right now and about nothing else - it moves no cursor, needs no live
 * session and reads no journal.
 *
 * A list is answered by one list statement carrying the visibility expression of the module, plus a
 * second branch over the interaction set. Access is by the primary key and the predicate is
 * evaluated once per identifier, so a page of a hundred costs a hundred evaluations whatever the
 * portal holds. There is no batch rights API for an arbitrary list in the module - the batch methods
 * it does have are bound to a single parent - so the check is built on the predicate inside a list
 * query rather than on preloading operations.
 *
 * The second branch is not optional. The expression answers for the rights of Disk alone, and
 * everything visible through interaction is cut away by it. The branch goes through the very seam
 * the initial fetch confirms its interaction page with ({@see AccessSourceConfirmerInterface},
 * ALG-05), because the rule of visibility of the check and of the fetch is one rule: with a rule of
 * its own here, the event of an external link being switched off would be idle - the client would
 * check, be told "you see it", and keep an object whose access is already gone.
 *
 * Three things the check deliberately does not do:
 *
 *  - it never asks whether an object exists. An object hidden from the reader and an object that was
 *    never there leave by the same path, so the answer cannot be turned into an oracle of existence
 *    nor walked to enumerate the identifiers of a portal, and OBJECT_NOT_FOUND has no place in it;
 *  - it applies no depth of history. Bounded by depth it would answer "you do not see it" about a
 *    visible object that simply had not changed for a long time, and the client would delete a live
 *    file. The consequence is accepted: after a check the memory of a client may hold objects older
 *    than the published depth;
 *  - it filters no trashcan. An object in the trashcan is still an object the reader sees, and a
 *    filter on the type of deletion would have the check tell the client to forget it.
 */
final class ListModeChecker
{
	/**
	 * The columns the visibility expression of the module is written over. The predicate is
	 * correlated, so the rows it runs on are narrowed by the identifiers of the list first.
	 */
	private const RIGHTS_COLUMNS = ['ID', 'CREATED_BY'];

	/**
	 * The columns of the interaction set a row is looked up by. The cards of the contour name the
	 * real object, while a row written when a link was opened carries the link, and the access of the
	 * user may stand on either of them.
	 */
	private const INTERACTION_COLUMNS = ['OBJECT_ID', 'REAL_OBJECT_ID'];

	private SyncLimits $limits;
	private AccessSourceConfirmerInterface $accessSourceConfirmer;

	public function __construct(
		?SyncLimits $limits = null,
		?AccessSourceConfirmerInterface $accessSourceConfirmer = null,
	)
	{
		$this->limits = $limits ?? new SyncLimits();
		$this->accessSourceConfirmer = $accessSourceConfirmer ?? new AccessSourceConfirmer();
	}

	/**
	 * One verdict per object asked about, in the order the list came in.
	 *
	 * The mode has no pagination of its own - the list is the page - so it is bounded by the
	 * published ceiling of a page. The bound is honoured here as well as by the method that validates
	 * the request, so that a caller which skipped the validation still cannot hand the database a
	 * list of unbounded length; what is beyond the ceiling is not answered about at all.
	 *
	 * @param int[] $objectIds objects of the memory of the client, as its cards name them
	 * @return CheckVerdict[]
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function check(int $userId, array $objectIds): array
	{
		$objectIds = $this->normaliseIds($objectIds);
		if ($objectIds === [])
		{
			return [];
		}

		$visible = $userId > 0 ? $this->findVisible($objectIds, $userId) : [];

		$verdicts = [];
		foreach ($objectIds as $objectId)
		{
			$verdicts[] = isset($visible[$objectId])
				? CheckVerdict::visible($objectId)
				: CheckVerdict::invisible($objectId)
			;
		}

		return $verdicts;
	}

	/**
	 * The two branches, the second asked only about what the first left undecided.
	 *
	 * An object the rights of Disk already show needs no second opinion, and the branches cost three
	 * statements over the interaction set between them, so a list of objects the reader owns outright
	 * is answered by the one query it deserves.
	 *
	 * @param int[] $objectIds
	 * @return array<int, true>
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	private function findVisible(array $objectIds, int $userId): array
	{
		$visible = $this->visibleByDiskRights($objectIds, $userId);

		$undecided = [];
		foreach ($objectIds as $objectId)
		{
			if (!isset($visible[$objectId]))
			{
				$undecided[] = $objectId;
			}
		}

		if ($undecided === [])
		{
			return $visible;
		}

		return $visible + $this->visibleByInteraction($undecided, $userId);
	}

	/**
	 * @param int[] $objectIds
	 * @return array<int, true>
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	private function visibleByDiskRights(array $objectIds, int $userId): array
	{
		$parameters = Driver::getInstance()->getRightsManager()->addRightsCheck(
			new DiskSecurityContext($userId),
			[
				'select' => ['ID'],
				'filter' => Query::filter()->whereIn('ID', $objectIds),
			],
			self::RIGHTS_COLUMNS,
		);

		$visible = [];
		$cursor = ObjectTable::getList($parameters);
		while ($row = $cursor->fetch())
		{
			$visible[(int)$row['ID']] = true;
		}

		return $visible;
	}

	/**
	 * ALG-05 over the rows the set holds about the objects asked for.
	 *
	 * Presence in the set is not visibility by itself, and the check does not treat it as such: the
	 * rows go to the same confirmation the fetch runs its interaction page through, and only a row
	 * whose source of access is still alive makes the object it names visible. A confirmed row speaks
	 * for both of its identifiers, of which those that were asked about become visible.
	 *
	 * @param int[] $objectIds
	 * @return array<int, true>
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	private function visibleByInteraction(array $objectIds, int $userId): array
	{
		$rows = $this->readInteractionRows($objectIds, $userId);
		if ($rows === [])
		{
			return [];
		}

		$asked = array_flip($objectIds);

		$visible = [];
		foreach ($this->accessSourceConfirmer->confirm($rows, $userId) as $row)
		{
			foreach ([$row['objectId'], $row['realObjectId']] as $identifier)
			{
				if (isset($asked[$identifier]))
				{
					$visible[$identifier] = true;
				}
			}
		}

		return $visible;
	}

	/**
	 * The rows of the interaction set that speak about the objects asked for.
	 *
	 * Two statements and not one: each of the two columns a row is looked up by has an index of its
	 * own, and an OR across the two is served by neither. A row answering by both columns comes back
	 * twice and is kept once, by its own key.
	 *
	 * @param int[] $objectIds
	 * @return array<int, array{
	 *     objectId: int,
	 *     realObjectId: int,
	 *     freshnessAt: DateTime,
	 *     attachedObjectId: int|null
	 * }>
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	private function readInteractionRows(array $objectIds, int $userId): array
	{
		$rows = [];
		foreach (self::INTERACTION_COLUMNS as $column)
		{
			$cursor = TrackedObjectTable::getList([
				'select' => ['ID', 'OBJECT_ID', 'REAL_OBJECT_ID', 'ATTACHED_OBJECT_ID', 'UPDATE_TIME'],
				'filter' => Query::filter()
					->where('USER_ID', $userId)
					->whereIn($column, $objectIds)
				,
			]);

			while ($row = $cursor->fetch())
			{
				$attachedObjectId = $row['ATTACHED_OBJECT_ID'] === null ? null : (int)$row['ATTACHED_OBJECT_ID'];

				$rows[(int)$row['ID']] = [
					'objectId' => (int)$row['OBJECT_ID'],
					'realObjectId' => (int)$row['REAL_OBJECT_ID'],
					'freshnessAt' => $row['UPDATE_TIME'],
					'attachedObjectId' => $attachedObjectId,
				];
			}
		}

		return array_values($rows);
	}

	/**
	 * @param int[] $objectIds
	 * @return int[]
	 */
	private function normaliseIds(array $objectIds): array
	{
		$normalised = [];
		foreach ($objectIds as $objectId)
		{
			$objectId = (int)$objectId;
			if ($objectId > 0)
			{
				$normalised[$objectId] = $objectId;
			}
		}

		return array_slice(array_values($normalised), 0, $this->limits->getPageSizeMax());
	}
}
