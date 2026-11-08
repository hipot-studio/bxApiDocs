<?php

namespace Bitrix\Bizproc\Api\Request\TaskService;

final class DoTaskCollectionRequest
{
	/**
	 * Task ids of the request, in the order they came, without repetitions: an id asked for twice is
	 * performed once and answered by a single outcome record of that id.
	 *
	 * @var int[]
	 */
	public /*readonly*/ array $taskIds;

	/**
	 * @param mixed[] $taskIds ids of any origin; non-numeric and non-positive ones are discarded
	 */
	public function __construct(
		array $taskIds,
		public /*readonly*/ int $userId,
		public /*readonly*/ array $taskRequest = [],
	)
	{
		$this->taskIds = self::normalizeTaskIds($taskIds);
	}

	/**
	 * @param mixed[] $taskIds
	 * @return int[]
	 */
	private static function normalizeTaskIds(array $taskIds): array
	{
		$normalizedTaskIds = [];
		foreach ($taskIds as $id)
		{
			// the standard ajax client sends form-encoded data, so integers arrive as strings
			if (!is_int($id) && !(is_string($id) && preg_match('/^[0-9]+$/D', $id) === 1))
			{
				continue;
			}

			$taskId = (int)$id;
			if ($taskId > 0)
			{
				$normalizedTaskIds[$taskId] = $taskId;
			}
		}

		return array_values($normalizedTaskIds);
	}
}
