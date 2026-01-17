<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository;

use Bitrix\Main\Type\DateTime;
use Bitrix\Tasks\Internals\Task\ParameterTable;

class TaskParameterRepository implements TaskParameterRepositoryInterface
{
	public function invalidate(int $taskId): void
	{
	}

	public function matchesSubTasksTime(int $taskId): bool
	{
		return $this->getParameter($taskId, ParameterTable::PARAM_SUBTASKS_TIME);
	}

	public function autocompleteSubTasks(int $taskId): bool
	{
		return $this->getParameter($taskId, ParameterTable::PARAM_SUBTASKS_AUTOCOMPLETE);
	}

	public function isResultRequired(int $taskId): bool
	{
		return $this->getParameter($taskId, ParameterTable::PARAM_RESULT_REQUIRED);
	}

	public function allowsChangeDatePlan(int $taskId): bool
	{
		return $this->getParameter($taskId, ParameterTable::PARAM_ALLOW_CHANGE_DATE_PLAN);
	}

	public function maxDeadlineChangeDate(int $taskId): ?DateTime
	{
		return $this->getDateTimeParameter($taskId, ParameterTable::PARAM_MAX_DEADLINE_CHANGE_DATE);
	}

	public function maxDeadlineChanges(int $taskId): ?int
	{
		return $this->getNumericParameter($taskId, ParameterTable::PARAM_MAX_DEADLINE_CHANGES);
	}

	public function requireDeadlineChangeReason(int $taskId): bool
	{
		return $this->getParameter($taskId, ParameterTable::PARAM_REQUIRE_DEADLINE_CHANGE_REASON);
	}

	private function getParameter(int $taskId, int $code): bool
	{
		$res = ParameterTable::query()
			->setSelect(['ID'])
			->where('TASK_ID', $taskId)
			->whereIn('CODE', $code)
			->where('VALUE', 'Y')
			->setLimit(1)
			->fetch()
		;

		return $res && (int)$res['ID'] > 0;
	}

	private function getNumericParameter(int $taskId, int $code): ?int
	{
		$res = ParameterTable::query()
			->setSelect(['VALUE'])
			->where('TASK_ID', $taskId)
			->whereIn('CODE', $code)
			->setLimit(1)
			->fetch()
		;

		if (!$res || !is_numeric($res['VALUE']))
		{
			return null;
		}

		return (int)$res['VALUE'];
	}

	private function getDateTimeParameter(int $taskId, int $code): ?DateTime
	{
		$res = ParameterTable::query()
			->setSelect(['VALUE'])
			->where('TASK_ID', $taskId)
			->whereIn('CODE', $code)
			->setLimit(1)
			->fetch()
		;

		if (!$res || empty($res['VALUE']) || !is_numeric($res['VALUE']))
		{
			return null;
		}

		$dateTime = DateTime::createFromTimestamp($res['VALUE']);
		$dateTime->setTimeZone(new \DateTimeZone('UTC'));

		return $dateTime;
	}
}
