<?php

namespace Bitrix\StaffTrack\Item;

/**
 * The codes of the selector read strictly: every department is already a node of the structure, and a
 * code the module cannot take is kept aside instead of being dropped.
 */
final class OfficeStaffSelection
{
	/**
	 * @param int[] $userIds
	 * @param int[] $flatNodeIds nodes whose own employees are taken, without the nested nodes
	 * @param int[] $deepNodeIds nodes taken with every nested node
	 * @param array<int, array{type: string, id: int|string}> $unsupportedCodes
	 */
	public function __construct(
		public readonly array $userIds = [],
		public readonly array $flatNodeIds = [],
		public readonly array $deepNodeIds = [],
		public readonly array $unsupportedCodes = [],
	) {}

	public function hasUnsupportedCodes(): bool
	{
		return !empty($this->unsupportedCodes);
	}
}
