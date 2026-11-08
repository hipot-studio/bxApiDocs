<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Task\Completion;

use Bitrix\Main\Result;
use Bitrix\Tasks\V2\Internal\Entity\CheckList;
use Bitrix\Tasks\V2\Internal\Repository\Mapper\CheckListMapper;
use Bitrix\Tasks\V2\Internal\Service\CheckList\CheckListService;
use Bitrix\Tasks\V2\Internal\Service\CheckList\CheckListTreeService;

class CheckListCompletionService
{
	public function __construct(
		private readonly CheckListCompletionSnapshotService $snapshotService,
		private readonly CheckListTreeService $checkListTreeService,
		private readonly CheckListMapper $checkListMapper,
		private readonly CheckListService $checkListService,
	)
	{
	}

	public function completeForTask(int $taskId, int $userId, bool $useBatch = true): Result
	{
		$result = new Result();

		$snapshotItemsFields = $this->snapshotService->getItemsByTaskId($taskId);
		$snapshot = $this->snapshotService->build(array_values($snapshotItemsFields));
		if ($snapshot === null)
		{
			return $result;
		}

		$taskItemsFields = $this->snapshotService->getFullItemsByTaskId($taskId);
		$checkList = $this->mapCheckList(
			taskId: $taskId,
			taskItemsFields: $taskItemsFields,
			snapshotItemsFields: $snapshotItemsFields,
		);

		$completionResult = $this->checkListService->completeStrict(
			ids: $snapshot['incompleteIds'],
			userId: $userId,
			taskId: $taskId,
			taskItemsFields: $taskItemsFields,
			existingCheckList: $checkList,
			useBatch: $useBatch,
		);
		if (!$completionResult->isSuccess())
		{
			$result->addErrors($completionResult->getErrors());
		}

		return $result;
	}

	private function mapCheckList(int $taskId, array $taskItemsFields, array $snapshotItemsFields): CheckList
	{
		foreach ($snapshotItemsFields as $id => $itemFields)
		{
			if (!isset($taskItemsFields[$id]))
			{
				$taskItemsFields[$id] = [
					...$itemFields,
					'TASK_ID' => $taskId,
					'ENTITY_ID' => $taskId,
					'MEMBERS' => [],
				];
			}
		}

		return $this->checkListMapper->mapToEntity(
			$this->checkListTreeService->buildTree($taskItemsFields),
		);
	}
}
