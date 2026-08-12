<?php

declare(strict_types=1);

namespace Bitrix\Mail\Integration\Tasks;

use Bitrix\Main\Loader;
use Bitrix\Main\Provider\Params\Pager;
use Bitrix\Main\Type\DateTime;
use Bitrix\Tasks\Internals\Task\Status as TaskStatus;
use Bitrix\Tasks\V2\Public\Provider\Params\SortDirection;
use Bitrix\Tasks\V2\Public\Provider\Params\TaskList\FieldsEnum;
use Bitrix\Tasks\V2\Public\Provider\Params\TaskList\TaskListArrayFilter;
use Bitrix\Tasks\V2\Public\Provider\Params\TaskList\TaskListParams;
use Bitrix\Tasks\V2\Public\Provider\Params\TaskList\TaskListSelect;
use Bitrix\Tasks\V2\Public\Provider\Params\TaskList\TaskListSort;
use Bitrix\Tasks\V2\Public\Provider\TaskProvider;

/**
 * Single point of integration between mail and tasks/V2.
 *
 * Encapsulates the use of TaskProvider and Internal task entities so the rest
 * of the mail module operates on plain arrays without importing tasks/V2/Internal.
 */
class TaskFinder
{
	public const STATE_OPEN = 'open';
	public const STATE_CLOSED = 'closed';

	/**
	 * Returns tasks linked to mail messages (UF_MAIL_MESSAGE > 0) matching the given criteria.
	 */
	public function findTasksLinkedToMail(
		int $userId,
		?string $state = null,
		?bool $overdued = null,
		?DateTime $createdFrom = null,
		?DateTime $createdTo = null,
		?int $responsibleId = null,
		?int $limit = 200,
		int $offset = 0,
	): array
	{
		if (!Loader::includeModule('tasks'))
		{
			return [];
		}

		$conditions = [
			[FieldsEnum::UfMailMessage->value, '>', 0],
		];

		$statuses = $overdued === true && $state !== self::STATE_CLOSED
			? TaskStatus::getInWorkStatuses()
			: $this->resolveStatuses($state);
		if (!empty($statuses))
		{
			$conditions[] = [FieldsEnum::RealStatus->value, 'in', $statuses];
		}

		if ($overdued === true)
		{
			$conditions[] = $state === self::STATE_CLOSED
				? [FieldsEnum::Overdued->value, '=', 'Y']
				: [FieldsEnum::Deadline->value, '<', new DateTime()];
		}

		if ($createdFrom !== null)
		{
			$conditions[] = [FieldsEnum::CreatedDate->value, '>=', $createdFrom];
		}

		if ($createdTo !== null)
		{
			$conditions[] = [FieldsEnum::CreatedDate->value, '<=', $createdTo];
		}

		if ($responsibleId !== null && $responsibleId > 0)
		{
			$conditions[] = [FieldsEnum::ResponsibleId->value, '=', $responsibleId];
		}

		$params = new TaskListParams(
			userId: $userId,
			pagination: new Pager(max(1, $limit ?? 200), max(0, $offset)),
			filter: new TaskListArrayFilter($conditions),
			sort: new TaskListSort([
				FieldsEnum::CreatedDate->value => SortDirection::Desc->value,
				FieldsEnum::Id->value => SortDirection::Desc->value,
			]),
			select: new TaskListSelect([
				FieldsEnum::Id->value,
				FieldsEnum::Title->value,
				FieldsEnum::RealStatus->value,
				FieldsEnum::Deadline->value,
				FieldsEnum::CreatedDate->value,
				FieldsEnum::ResponsibleId->value,
				FieldsEnum::UfMailMessage->value,
			]),
		);

		$collection = (new TaskProvider())->getList($params);

		$result = [];
		foreach ($collection as $task)
		{
			$emailId = (int)($task->email?->id ?? 0);
			if ($emailId <= 0)
			{
				continue;
			}

			$result[] = [
				'id' => (int)$task->id,
				'title' => (string)($task->title ?? ''),
				'status' => $task->status?->value,
				'deadlineTs' => $task->deadlineTs,
				'responsibleId' => $task->responsible?->id,
				'emailId' => $emailId,
			];
		}

		return $result;
	}

	private function resolveStatuses(?string $state): array
	{
		return match ($state)
		{
			self::STATE_OPEN => TaskStatus::getInWorkStatuses(),
			self::STATE_CLOSED => [
				TaskStatus::SUPPOSEDLY_COMPLETED,
				TaskStatus::COMPLETED,
				TaskStatus::DEFERRED,
				TaskStatus::DECLINED,
			],
			default => [],
		};
	}
}
