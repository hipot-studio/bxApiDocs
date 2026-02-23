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

	public function getTasks(?int $limit = null, ?array $sort = null): array
	{
		$decodedData = [];
		foreach ($this->archives as $archive)
		{
			$chunk = self::decodeTasksArchive($archive);
			foreach ($chunk as $row)
			{
				$decodedData[$row[0]] = $row;

				if (!is_null($sort) && count($decodedData) >= $limit)
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
			$task = current(array_filter($chunk, fn($chunk) => $chunk[0] === $taskId));

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
			'ID' => $task[0] ?? null,
			'NAME' => $task[1] ?? null,
			'DESCRIPTION' => $task[2] ?? null,
			'STATUS' => $task[3] ?? null,
			'CREATED_DATE' => $task[4] ?? null,
			'MODIFIED' => $task[5] ?? null,
			'USERS' => $task[6] ?? [],
		];

		if (isset($task['CREATED_DATE']))
		{
			$task['CREATED_DATE'] = DateTime::createFromTimestamp($task['CREATED_DATE']);
		}
		$task['MODIFIED'] = DateTime::createFromTimestamp($task['MODIFIED']);
		foreach ($task['USERS'] as &$taskUser)
		{

			$taskUser = [
				'USER_ID' => $taskUser[0] ?? null,
				'STATUS' => $taskUser[1] ?? null,
				'DATE_UPDATE' => $taskUser[2] ?? null,
			];

			$taskUser['DATE_UPDATE'] = DateTime::createFromTimestamp($taskUser['DATE_UPDATE']);
		}

		return $task;
	}

	private function getColumnsMap(array $sort): array
	{
		$map = [
			'ID' => 0,
			'NAME' => 1,
			'DESCRIPTION' => 2,
			'STATUS' => 3,
			'CREATED_DATE' => 4,
			'MODIFIED' => 5,
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
