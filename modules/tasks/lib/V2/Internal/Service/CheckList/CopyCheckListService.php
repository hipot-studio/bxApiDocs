<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\CheckList;

use Bitrix\Tasks\CheckList\Internals\CheckList;
use Bitrix\Tasks\CheckList\Task\TaskCheckListFacade;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Service\Task\UpdateService;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Update\Config\UpdateConfig;

class CopyCheckListService
{
	public function __construct(
		private readonly UpdateService $updateService,
		private readonly CheckListService $checkListService,
	)
	{
	}

	public function copy(
		int $fromTaskId,
		int $toTaskId,
		int $userId,
		?array $checkLists = [],
	): void
	{
		if (!empty($checkLists))
		{
			$this->checkListService->save(
				checklists: $checkLists,
				taskId: $toTaskId,
				userId: $userId,
			);

			return;
		}

		$sourceCheckListItems = TaskCheckListFacade::getByEntityId($fromTaskId);
		if (empty($sourceCheckListItems))
		{
			return;
		}

		$checkListItemsToCopy = [];
		foreach ($sourceCheckListItems as $id => $item)
		{
			$item['COPIED_ID'] = $item['ID'];
			unset($item['ID']);

			$checkListItemsToCopy[$id] = $item;
		}

		$roots = TaskCheckListFacade::getObjectStructuredRoots(
			$checkListItemsToCopy,
			$toTaskId,
			$userId,
		);

		$copiedIds = [];

		foreach ($roots as $checkList)
		{
			/** @var CheckList $checkList */
			$result = $checkList->save();

			$checkListData = $result->getData();
			if ($checkListData === null)
			{
				return;
			}

			/** @var CheckList $copiedCheckList */
			$copiedCheckList = $checkListData['ITEM'];
			$copiedIds[] = $copiedCheckList->getFields()['ID'];
		}

		$task = new Entity\Task(id: $toTaskId, checklist: $copiedIds);
		$config = new UpdateConfig($userId);

		$this->updateService->update(
			task: $task,
			config: $config,
		);
	}
}
