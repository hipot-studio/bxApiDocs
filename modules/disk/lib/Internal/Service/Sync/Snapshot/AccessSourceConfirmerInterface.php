<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Sync\Snapshot;

use Bitrix\Main\Type\DateTime;

/**
 * The seam the stream of the interaction set hands its rows through before they become visible.
 *
 * Presence in the set is not visibility by itself: the access a row was written for may have been
 * revoked since - the rights of Disk taken away, the external or unified link switched off - and
 * without this confirmation the event of such a revocation would be idle, with the client still
 * being told it may see the object. Whoever implements it owes two things: the answer is batch for
 * the whole page, and it never leaves the module for a connector of another one.
 *
 * The implementation is ALG-05 and belongs to the interaction stream:
 * {@see \Bitrix\Disk\Internal\Service\Sync\Visibility\AccessSourceConfirmer}. It is a seam and not
 * a method of {@see SnapshotService} so that the sources a portal can confirm may grow - the
 * connectors of the neighbouring modules are due at stage 3 - without the page of the fetch being
 * touched again.
 *
 * The first of the sources is asked for separately as well. Of the three it is the only personal
 * one, and a caller holding objects rather than rows of the set - the journal holds records, and a
 * record is addressed by codes and not by a set - may ask for nothing else: a link answers for
 * whoever holds it, so asked about an object nobody touched it would confirm the object to any
 * reader at all.
 */
interface AccessSourceConfirmerInterface
{
	/**
	 * Which of these objects the reader sees by the rights of Disk, answered for all of them at once.
	 *
	 * @param int[] $objectIds
	 * @return array<int, true> keyed by the object
	 */
	public function visibleByDiskRights(array $objectIds, int $userId): array;

	/**
	 * @param array<int, array{
	 *     objectId: int,
	 *     realObjectId: int,
	 *     freshnessAt: DateTime,
	 *     attachedObjectId: int|null
	 * }> $rows one page worth of rows of the interaction set, in the order of the stream
	 * @return array<int, array{
	 *     objectId: int,
	 *     realObjectId: int,
	 *     freshnessAt: DateTime,
	 *     attachedObjectId: int|null
	 * }> the rows whose source of access is confirmed, in the order they came in
	 */
	public function confirm(array $rows, int $userId): array;
}
