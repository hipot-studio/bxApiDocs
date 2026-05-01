<?php

declare(strict_types=1);

namespace Bitrix\Tasks\Internals\Counter\Queue;

use Bitrix\Tasks\Internals\Counter\CounterDictionary;
use Bitrix\Tasks\Internals\Counter\CounterTable;
use Bitrix\Tasks\Update\AgentInterface;

class RecountCounterAgent implements AgentInterface
{
	private const ROW_LIMIT = 1;
	private const COUNTER_NAME = 'auditor_expired';
	private bool $needContinue = false;

	public static function execute(): string
	{
		return (new self())->run();
	}

	private function getAgentName(): string
	{
		return static::class . '::execute();';
	}

	public function run(): string
	{
		$this->needContinue = false;

		$recountName = $this->getRecountName();
		if (!$recountName)
		{
			return '';
		}

		$userTasksList = $this->getUserTasksMapToRecount();

		if (!$userTasksList)
		{
			return '';
		}

		$this->addUserTaskRecountInQueue($recountName, $userTasksList);

		return $this->needContinue
			? $this->getAgentName()
			: '';
	}

	private function getUserTasksMapToRecount(): array
	{
		$foundCounters = CounterTable::query()
			->setSelect(['TASK_ID', 'USER_ID'])
			->where('TYPE', '=', self::COUNTER_NAME)
			->setLimit(self::ROW_LIMIT)
			->exec();

		$index = 0;
		$userTasksList = [];

		while ($row = $foundCounters->Fetch())
		{
			$userTasksList[$row['USER_ID']] = $userTasksList[$row['USER_ID']] ?? [];
			$userTasksList[$row['USER_ID']][] = $row['TASK_ID'];
			++$index;
		}

		$this->needContinue = $index >= self::ROW_LIMIT;

		return $userTasksList;
	}

	private function addUserTaskRecountInQueue(string $recountName, array $userTasksList): void
	{
		$queue = Queue::getInstance();

		foreach ($userTasksList as $userId => $taskIds)
		{
			$queue->add($userId, $recountName, $taskIds);
		}

		(new Agent)->addAgent();
	}

	private function getRecountName(): string
	{
		if (
			in_array(self::COUNTER_NAME, CounterDictionary::MAP_EXPIRED, true)
			|| in_array(self::COUNTER_NAME, CounterDictionary::MAP_MUTED_EXPIRED, true)
		)
		{
			return CounterDictionary::COUNTER_EXPIRED;
		}

		if (
			in_array(self::COUNTER_NAME, CounterDictionary::MAP_COMMENTS, true)
			|| in_array(self::COUNTER_NAME, CounterDictionary::MAP_MUTED_COMMENTS, true)
		)
		{
			return CounterDictionary::COUNTER_NEW_COMMENTS;
		}

		return '';
	}
}
