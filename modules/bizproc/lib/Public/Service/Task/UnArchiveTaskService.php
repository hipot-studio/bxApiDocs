<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Service\Task;

use Bitrix\Main\Type\Collection;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Web\Json;

class UnArchiveTaskService
{
	private array $archives;
	public function __construct(string|array $archives, readonly bool $compatibilityMode = false)
	{
		$this->archives = !is_array($archives) ? [$archives] : $archives;
	}

	public function getTasks(?int $limit = null, ?array $sort = null, bool $raw = false): array
	{
		$decodedData = [];
		foreach ($this->archives as $archive)
		{
			$chunk = self::decodeTasksArchive($archive);
			foreach ($chunk as $row)
			{
				$decodedData[$row[ArchiveTaskService::TASK_ID]] = $row;

				if (!is_null($sort) && (!is_null($limit) && count($decodedData) >= $limit))
				{
					break 2;
				}
			}
		}

		if ($sort)
		{
			Collection::sortByColumn($decodedData, $this->getColumnsMap($sort));
		}
		if ($limit)
		{
			$decodedData = array_slice($decodedData, 0, $limit);
		}

		if ($raw)
		{
			return $decodedData;
		}

		foreach ($decodedData as &$task)
		{
			$task = $this->prepareTaskData($task);
		}
		unset($task);

		if ($this->compatibilityMode)
		{
			return $this->makeTildaTasksData($decodedData);
		}

		return $decodedData;
	}

	public function getTask(int $taskId): ?array
	{
		$task = null;
		foreach ($this->archives as $archive)
		{
			$chunk = self::decodeTasksArchive($archive);
			$task = current(array_filter($chunk, fn($chunk) => $chunk[ArchiveTaskService::TASK_ID] === $taskId));

			if ($task)
			{
				return $this->prepareTaskData($task);
			}
		}

		return $task;
	}

	private function prepareTaskData(array $task): array
	{
		$task = [
			'ID' => $task[ArchiveTaskService::TASK_ID] ?? null,
			'NAME' => $task[ArchiveTaskService::TASK_NAME] ?? null,
			'DESCRIPTION' => $task[ArchiveTaskService::TASK_DESCRIPTION] ?? null,
			'STATUS' => $task[ArchiveTaskService::TASK_STATUS] ?? null,
			'CREATED_DATE' => $task[ArchiveTaskService::TASK_CREATED_DATE] ?? null,
			'MODIFIED' => $task[ArchiveTaskService::TASK_MODIFIED] ?? null,
			'USERS' => $task[ArchiveTaskService::TASK_USERS] ?? [],
		];

		if (isset($task['CREATED_DATE']))
		{
			$task['CREATED_DATE'] = DateTime::createFromTimestamp($task['CREATED_DATE']);
		}
		$task['MODIFIED'] = DateTime::createFromTimestamp($task['MODIFIED']);
		foreach ($task['USERS'] as &$taskUser)
		{
			$taskUser = [
				'USER_ID' => $taskUser[ArchiveTaskService::USER_ID] ?? null,
				'STATUS' => $taskUser[ArchiveTaskService::USER_STATUS] ?? null,
				'DATE_UPDATE' => $taskUser[ArchiveTaskService::USER_DATE_UPDATE] ?? null,
			];

			$taskUser['DATE_UPDATE'] = DateTime::createFromTimestamp($taskUser['DATE_UPDATE']);
		}

		return $task;
	}

	private function getColumnsMap(array $sort): array
	{
		$map = [
			'ID' => ArchiveTaskService::TASK_ID,
			'NAME' => ArchiveTaskService::TASK_NAME,
			'DESCRIPTION' => ArchiveTaskService::TASK_DESCRIPTION,
			'STATUS' => ArchiveTaskService::TASK_STATUS,
			'CREATED_DATE' => ArchiveTaskService::TASK_CREATED_DATE,
			'MODIFIED' => ArchiveTaskService::TASK_MODIFIED,
		];

		$newSort = [];
		foreach ($sort as $column => $order)
		{
			$newSort[$map[$column]] = $order;
		}

		return $newSort;
	}

	private function makeTildaTasksData(array $taskData): array
	{
		$tildaData = [];
		foreach ($taskData as $task)
		{
			$data = [];
			foreach ($task as $key => $value)
			{
				if (is_string($value) && $value !== '' && preg_match("/[;&<>\"]/", $value))
				{
					$data[$key] = htmlspecialcharsbx($value);
				}
				else
				{
					$data[$key] = $value;
				}
				$data['~' . $key] = $value;
			}
			$tildaData[$task['ID']] = $data;
		}

		return $tildaData;
	}

	public static function decodeTasksArchive(string $data): array
	{
		$decodedData = $data;
		if (function_exists('gzuncompress'))
		{
			$decodedData = @gzuncompress($decodedData) ?: '';
		}

		return Json::decode($decodedData);
	}
}
