<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\CheckList;

use Bitrix\Tasks\CheckList\Internals\CheckList;
use Bitrix\Tasks\CheckList\Task\TaskCheckListFacade;
use Bitrix\Tasks\CheckList\Template\TemplateCheckListFacade;

class TaskCheckListToTemplateService
{
	public function copy(int $taskId, int $templateId, int $userId): void
	{
		$sourceCheckListItems = TaskCheckListFacade::getByEntityId($taskId);
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

		$roots = TemplateCheckListFacade::getObjectStructuredRoots(
			items: $checkListItemsToCopy,
			entityId: $templateId,
			userId: $userId,
		);

		foreach ($roots as $checkList)
		{
			/** @var CheckList $checkList */
			$checkList->save();
		}
	}
}
