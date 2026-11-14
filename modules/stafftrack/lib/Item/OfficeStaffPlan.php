<?php

namespace Bitrix\StaffTrack\Item;

/**
 * The staff a selection expands into at the moment it was read: the people themselves rather than the
 * departments they came from, so a person taken on afterwards is not in it.
 */
final class OfficeStaffPlan
{
	/**
	 * @param int[] $userIds
	 * @param array<int, int> $moves user id => the office they are assigned to right now
	 * @param string $fingerprint the moves folded into one opaque string, empty when there are none
	 * @param int[] $addedUserIds those of the staff who are neither on the target office nor removed
	 * @param int[] $removedMemberIds the removed who are on the target office right now, ascending
	 */
	public function __construct(
		public readonly array $userIds,
		public readonly int $total,
		public readonly array $moves,
		public readonly string $fingerprint,
		public readonly array $addedUserIds,
		public readonly array $removedMemberIds,
	) {}
}
