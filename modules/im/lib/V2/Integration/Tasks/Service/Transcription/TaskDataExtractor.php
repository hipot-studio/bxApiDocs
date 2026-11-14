<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\Tasks\Service\Transcription;

/**
 * Reads the task list out of an AI verdict.
 *
 * The prompt returns "tasks" as an array, but the previous prompt version returns a single
 * "task_data" object and is not tied to the module version, so both shapes are accepted.
 */
class TaskDataExtractor
{
	private const TASKS_FIELD = 'tasks';
	private const TASK_DATA_FIELD = 'task_data';

	/**
	 * @return array<int, array> non-empty task data items in the order returned by the model
	 */
	public function extract(array $data): array
	{
		$rawTasks = $data[self::TASKS_FIELD] ?? $data[self::TASK_DATA_FIELD] ?? [];

		if (!is_array($rawTasks) || empty($rawTasks))
		{
			return [];
		}

		if (!array_is_list($rawTasks))
		{
			$rawTasks = [$rawTasks];
		}

		$tasks = [];
		foreach ($rawTasks as $rawTask)
		{
			if (
				!is_array($rawTask)
				|| empty($rawTask)
				|| (isset($rawTask['title']) && !is_string($rawTask['title']))
			)
			{
				continue;
			}

			$tasks[] = $rawTask;
		}

		return $tasks;
	}

}
