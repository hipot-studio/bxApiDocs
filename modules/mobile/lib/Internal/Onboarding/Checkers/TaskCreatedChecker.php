<?php

namespace Bitrix\Mobile\Internal\Onboarding\Checkers;

use Bitrix\Main\Provider\Params\Pager;
use Bitrix\Main\Type\DateTime;
use Bitrix\Tasks\V2\Public\Provider\Params\TaskList\FieldsEnum;
use Bitrix\Tasks\V2\Public\Provider\Params\TaskList\TaskListArrayFilter;
use Bitrix\Tasks\V2\Public\Provider\Params\TaskList\TaskListParams;
use Bitrix\Tasks\V2\Public\Provider\Params\TaskList\TaskListSelect;
use Bitrix\Tasks\V2\Public\Provider\TaskProvider;

/**
 * Asked of the public provider of tasks rather than of the table behind it: the table belongs
 * to the module of tasks and may change with it, while the provider is the contract it offers
 * across the boundary.
 *
 * One difference comes with that contract: its filter has no zombie field, so a task already
 * marked for the recycle bin answers this check as well. The step asks whether the user ever
 * made a task, and a made-then-deleted one was made.
 *
 * Deliberately not a bulk check, unlike its neighbours for invitations and deals. The per-user
 * query lands on `CREATED_BY` as a `ref` of one row, while the grouped one over a selection
 * falls back to a range over the date index: reading the selection at once would trade a
 * one-row lookup for a scan of the date range of the campaign.
 */
class TaskCreatedChecker extends RecordExistsStepChecker
{
	private ?TaskProvider $taskProvider = null;

	protected function getModuleId(): string
	{
		return 'tasks';
	}

	protected function recordExists(int $userId, DateTime $sinceDate): bool
	{
		return $this->hasTask($this->createListParams($userId, $sinceDate));
	}

	/**
	 * What is asked: a task of this user made since the anchor of the series, one row of it.
	 * The rights of nobody are consulted - the pass runs for the portal and judges the fact.
	 */
	protected function createListParams(int $userId, DateTime $sinceDate): TaskListParams
	{
		return new TaskListParams(
			userId: $userId,
			pagination: new Pager(1),
			filter: new TaskListArrayFilter([
				[FieldsEnum::CreatedBy->value, '=', $userId],
				[FieldsEnum::CreatedDate->value, '>=', $sinceDate],
			]),
			select: new TaskListSelect([FieldsEnum::Id->value]),
			skipAccessCheck: true,
		);
	}

	/**
	 * The seam the question leaves through, so a test sees what was asked rather than that
	 * something was. The provider is kept for the whole pass: its constructor resolves a dozen
	 * services of the tasks container, and the pass asks this once per user of the portal.
	 */
	protected function hasTask(TaskListParams $params): bool
	{
		$this->taskProvider ??= new TaskProvider();

		return !$this->taskProvider->getList($params)->isEmpty();
	}
}
