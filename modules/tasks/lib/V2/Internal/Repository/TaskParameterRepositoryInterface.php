<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository;

interface TaskParameterRepositoryInterface
{
	public function invalidate(int $taskId): void;

	public function matchesSubTasksTime(int $taskId): bool;

	public function autocompleteSubTasks(int $taskId): bool;

	public function isResultRequired(int $taskId): bool;

	public function allowsChangeDatePlan(int $taskId): bool;

	public function maxDeadlineChangeDate(int $taskId): ?\Bitrix\Main\Type\DateTime;

	public function maxDeadlineChanges(int $taskId): ?int;

	public function requireDeadlineChangeReason(int $taskId): bool;
}
