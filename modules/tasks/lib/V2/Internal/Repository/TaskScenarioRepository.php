<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository;

use Bitrix\Tasks\Internals\Task\ScenarioTable;
use Bitrix\Tasks\V2\Internal\Entity\Task\ScenarioCollection;

class TaskScenarioRepository implements TaskScenarioRepositoryInterface
{
	public function getById(int $taskId): ScenarioCollection
	{
		$rows = ScenarioTable::query()
			->setSelect(['SCENARIO'])
			->where('TASK_ID', $taskId)
			->fetchAll();

		$values = array_column($rows, 'SCENARIO');

		return ScenarioCollection::mapFromArray($values);
	}

	public function save(int $taskId, array $scenarios): void
	{
		if (empty($scenarios))
		{
			return;
		}

		$rows = [];
		foreach ($scenarios as $scenario)
		{
			$rows[] = [
				'TASK_ID' => $taskId,
				'SCENARIO' => $scenario,
			];
		}

		ScenarioTable::addInsertIgnoreMulti($rows, true);
	}

	public function delete(int $taskId): void
	{
		ScenarioTable::delete($taskId);
	}
}
