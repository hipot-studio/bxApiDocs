<?php

namespace Bitrix\Bizproc\Api\Response\TaskService;

use Bitrix\Bizproc\Result;

final class DoTaskCollectionResponse extends Result
{
	public const STATUS_DONE = 'done';
	public const STATUS_NOT_FOUND = 'not_found';
	public const STATUS_ACCESS_DENIED = 'access_denied';
	public const STATUS_ERROR = 'error';

	public function __construct()
	{
		parent::__construct();

		$this->data = [
			'results' => [],
			'doneCount' => 0,
		];
	}

	/**
	 * @param array $errors list of ['message' => string, 'code' => string|null, 'fieldId' => string|null],
	 *                      filled for self::STATUS_ERROR only
	 */
	public function addTaskResult(int $taskId, string $status, array $errors = []): self
	{
		$this->data['results'][] = [
			'taskId' => $taskId,
			'status' => $status,
			'errors' => $errors,
		];

		if ($status === self::STATUS_DONE)
		{
			$this->data['doneCount']++;
		}

		return $this;
	}

	public function getResults(): array
	{
		return $this->data['results'];
	}

	public function getDoneCount(): int
	{
		return $this->data['doneCount'];
	}
}
