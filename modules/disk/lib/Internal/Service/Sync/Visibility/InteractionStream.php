<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Sync\Visibility;

use Bitrix\Disk\Internals\TrackedObjectTable;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\DateTime;

/**
 * The stream of the objects the user has interacted with, read one page-worth at a time.
 *
 * What carries visibility by interaction is the set of tracked objects and nothing else. The recently
 * used objects of the module take part in the order inside the first phase only and add no visibility
 * of their own: their reading is filtered by rights anyway, so they can say nothing the stream of
 * files has not said already.
 *
 * Two properties separate this stream from the stream of files and are the reason the two are never
 * merged into one ordered sequence:
 *
 *  - it is ordered by when the user last touched the object rather than by when the object changed,
 *    and it lives under a boundary of its own, the one the session fixed for interaction;
 *  - it is read for the user as a whole rather than storage by storage, because an object seen
 *    through interaction may well sit outside the storages of the user, and that is the case the
 *    set exists for; nor does it carry a rights predicate at all.
 *
 * What a row of the set means for the fetch is decided afterwards, by the confirmation of the source
 * of access (ALG-05): presence in the set is not visibility by itself. The listing path the module
 * already has over the set checks no rights either and cannot be reused as it is.
 */
final class InteractionStream
{
	/**
	 * One ordered read of the set: the rows the user touched no later than the boundary of the
	 * session, freshest first, starting after the position the previous read stopped at.
	 *
	 * The identifier of the object, not of the row of the set, carries the keyset: the pair of the
	 * user and the object is unique in the set, so it orders the stream as well as the row key would
	 * and keeps the position of the token meaning the same thing in every stream.
	 *
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
	public function read(
		int $userId,
		DateTime $interactedTo,
		?DateTime $afterFreshnessAt,
		?int $afterObjectId,
		int $take,
	): array
	{
		if ($userId <= 0 || $take <= 0)
		{
			return [];
		}

		$filter = Query::filter()
			->where('USER_ID', $userId)
			->where('UPDATE_TIME', '<=', $interactedTo)
		;

		if ($afterFreshnessAt !== null && $afterObjectId !== null)
		{
			// The range on the key column is kept as a range so that the index of the stream still
			// serves the ordering; the tie on the identifier is a residual condition over the few
			// rows sharing a second.
			$filter
				->where('UPDATE_TIME', '<=', $afterFreshnessAt)
				->where(
					Query::filter()
						->logic('or')
						->where('UPDATE_TIME', '<', $afterFreshnessAt)
						->where('OBJECT_ID', '<', $afterObjectId)
					,
				)
			;
		}

		$rows = [];
		$cursor = TrackedObjectTable::getList([
			'select' => ['OBJECT_ID', 'REAL_OBJECT_ID', 'ATTACHED_OBJECT_ID', 'UPDATE_TIME'],
			'filter' => $filter,
			'order' => ['UPDATE_TIME' => 'DESC', 'OBJECT_ID' => 'DESC'],
			'limit' => $take,
		]);
		while ($row = $cursor->fetch())
		{
			$attachedObjectId = $row['ATTACHED_OBJECT_ID'] === null ? null : (int)$row['ATTACHED_OBJECT_ID'];

			$rows[] = [
				'objectId' => (int)$row['OBJECT_ID'],
				'realObjectId' => (int)$row['REAL_OBJECT_ID'],
				'freshnessAt' => $row['UPDATE_TIME'],
				'attachedObjectId' => $attachedObjectId,
			];
		}

		return $rows;
	}
}
