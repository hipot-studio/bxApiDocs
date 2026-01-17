<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository;

use Bitrix\Main\Type\DateTime;
use Bitrix\Tasks\Internals\Task\ParameterTable;

class InMemoryTaskParameterRepository implements TaskParameterRepositoryInterface
{
	private array $cache = [];

	public function invalidate(int $taskId): void
	{
		unset($this->cache[$taskId]);
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
		$parameters = $this->getParametersWithValues($taskId);
		$value = $parameters[$code] ?? null;
		
		return $value === 'Y';
	}

	private function getNumericParameter(int $taskId, int $code): ?int
	{
		$parameters = $this->getParametersWithValues($taskId);
		$value = $parameters[$code] ?? null;
		
		if (!is_numeric($value))
		{
			return null;
		}

		return (int)$value;
	}

	private function getDateTimeParameter(int $taskId, int $code): ?DateTime
	{
		$parameters = $this->getParametersWithValues($taskId);
		$value = $parameters[$code] ?? null;
		
		if (empty($value) || !is_numeric($value))
		{
			return null;
		}

		$dateTime = DateTime::createFromTimestamp($value);
		$dateTime->setTimeZone(new \DateTimeZone('UTC'));

		return $dateTime;
	}

	private function getParametersWithValues(int $taskId): array
	{
		$this->cache[$taskId] ??= array_column($this->fetchParameters($taskId), 'VALUE', 'CODE');

		return $this->cache[$taskId];
	}

	private function fetchParameters(int $taskId): array
	{
		$codes = ParameterTable::paramsList();

		return ParameterTable::query()
			->setSelect(['CODE', 'VALUE'])
			->where('TASK_ID', $taskId)
			->whereIn('CODE', $codes)
			->setLimit(count($codes))
			->fetchAll()
		;
	}
}
