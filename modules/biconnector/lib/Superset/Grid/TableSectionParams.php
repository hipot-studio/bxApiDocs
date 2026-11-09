<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Superset\Grid;

/**
 * What the template of the tables section needs to know about the groups.
 *
 * The answer travels to the template as plain values: the template draws by them and asks nothing
 * of the rights itself.
 */
final class TableSectionParams
{
	private TableGroupRepository $groupRepository;

	public function __construct(?TableGroupRepository $groupRepository = null)
	{
		$this->groupRepository = $groupRepository ?? new TableGroupRepository();
	}

	/**
	 * The level of the section is not among these values: it lives in the condition of the grid
	 * filter, which stores it on the user by itself.
	 *
	 * The rows of the groups are not among them either: the template draws no group of its own, the
	 * grid asks the repository for them by itself, and a copy handed over here would be read by
	 * nobody while costing the hit the reads it is built of.
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array
	{
		// The groups are arranged in either state of the axis; the rule of the action tells by which right.
		return [
			'IS_AVAILABLE_GROUP_MANAGEMENT' => $this->groupRepository->isGroupManager(),
		];
	}
}
