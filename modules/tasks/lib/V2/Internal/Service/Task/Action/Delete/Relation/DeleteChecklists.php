<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Task\Action\Delete\Relation;

use Bitrix\Tasks\CheckList\Task\TaskCheckListFacade;
use Bitrix\Tasks\V2\Internal\Entity\CheckList\Type;
use Bitrix\Tasks\V2\Internal\Repository\CheckListRevisionRepository;

class DeleteChecklists
{
	public function __invoke(array $fullTaskData): void
	{
		$taskId = (int)$fullTaskData['ID'];

		TaskCheckListFacade::deleteByEntityIdOnLowLevel($taskId);

		(new CheckListRevisionRepository())->deleteRevision($taskId, Type::Task);
	}
}
