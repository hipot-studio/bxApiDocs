<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Sync\Card;

use Bitrix\Disk\Access\AccessCodeEnum;
use Bitrix\Disk\Internals\ObjectPathTable;
use Bitrix\Disk\Internals\SharingTable;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;

/**
 * Which objects of a page are still to gain a place.
 *
 * An empty list of places stands for three different states, and a client cannot answer all three
 * the same way. Where an invitation has been sent and not yet answered, the place appears the moment
 * the person accepts it and the file is worth holding on to. Where the object is seen through
 * interaction alone, and where it lies under a link living in a storage whose root is closed, no
 * place ever appears - and a client waiting for one waits for ever.
 *
 * The answer is therefore read off the invitation rather than off the source of visibility. The
 * obvious formula - visible by the rights of Disk against visible through interaction alone - puts
 * the third state into the first and promises a place that never comes.
 *
 * Only an invitation addressed to the person themselves is counted, and that is not a narrowing but
 * the whole of it: a share to a department or to a chat is written as accepted, a share to a
 * workgroup is accepted at once, and a share to a department is additionally written out as a row
 * per person. An unanswered invitation is always a personal one, so expanding the access codes of
 * the reader would only promise a place for a group invitation nobody has anything to accept with.
 */
final class PlaceExpectation
{
	/**
	 * How many identifiers one statement carries. A page is smaller than this, and the chunking is
	 * here so that a caller assembling several pages at once still hands the database a size it
	 * plans well.
	 */
	private const IDS_PER_QUERY = 300;

	/**
	 * The objects the unanswered invitations of a user stand on, kept for the life of the collector:
	 * one page is assembled in more than one call, and the invitations of a person do not change
	 * between them.
	 *
	 * @var array<int, int[]>
	 */
	private array $invitedObjects = [];

	/**
	 * The objects of the page that are still to gain a place, as a set.
	 *
	 * Two statements, both over an index. The first names the objects the unanswered invitations of
	 * the reader stand on; the second intersects the page with the subtrees of those objects over the
	 * closure table. A row of depth zero is an object standing over itself, so the condition on the
	 * ancestor covers an invitation on the object itself as well and needs no branch of its own.
	 *
	 * Neither the rights of the module nor the storages the reader sees narrow this, and the two must
	 * not be confused for one another: the search of places travels towards an object and grows with
	 * the number of people it was shared to, which is what the narrowing there is for, while this one
	 * travels up the chain of ancestors of one object, which is never longer than the tree is deep.
	 * An invitation standing on an ancestor in a storage the reader cannot see is an invitation of
	 * theirs all the same, and nothing of that ancestor is published: what leaves is one flag about an
	 * object the page is handing over anyway.
	 *
	 * @param int[] $objectIds real objects of the page whose list of places came back empty
	 * @return array<int, true> keyed by the identifier of the object
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function findExpecting(int $userId, array $objectIds): array
	{
		$objectIds = self::normaliseIds($objectIds);
		if ($objectIds === [] || $userId <= 0)
		{
			return [];
		}

		$invited = $this->invitedObjectsOf($userId);
		if ($invited === [])
		{
			return [];
		}

		$expecting = [];
		foreach (array_chunk($objectIds, self::IDS_PER_QUERY) as $chunk)
		{
			// The same object under two invitations comes back twice; the set folds the repetition
			// here rather than asking the database for a pass of its own to fold it.
			$rows = ObjectPathTable::query()
				->setSelect(['OBJECT_ID'])
				->whereIn('OBJECT_ID', $chunk)
				->whereIn('PARENT_ID', $invited)
				->fetchAll()
			;

			foreach ($rows as $row)
			{
				$expecting[(int)$row['OBJECT_ID']] = true;
			}
		}

		return $expecting;
	}

	/**
	 * The objects the unanswered invitations of the user stand on, asked once per user.
	 *
	 * @return int[]
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	private function invitedObjectsOf(int $userId): array
	{
		if (isset($this->invitedObjects[$userId]))
		{
			return $this->invitedObjects[$userId];
		}

		$rows = SharingTable::query()
			->setSelect(['REAL_OBJECT_ID'])
			->where('TO_ENTITY', AccessCodeEnum::USER->value . $userId)
			->where('STATUS', SharingTable::STATUS_IS_UNREPLIED)
			->fetchAll()
		;

		$invited = [];
		foreach ($rows as $row)
		{
			$objectId = (int)$row['REAL_OBJECT_ID'];
			if ($objectId > 0)
			{
				$invited[$objectId] = $objectId;
			}
		}

		return $this->invitedObjects[$userId] = array_values($invited);
	}

	/**
	 * @param int[] $ids
	 * @return int[]
	 */
	private static function normaliseIds(array $ids): array
	{
		$normalised = [];
		foreach ($ids as $id)
		{
			$id = (int)$id;
			if ($id > 0)
			{
				$normalised[$id] = $id;
			}
		}

		return array_values($normalised);
	}
}
