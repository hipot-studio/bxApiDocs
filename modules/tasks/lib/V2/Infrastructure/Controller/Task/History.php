<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Infrastructure\Controller\Task;

use Bitrix\Main\Engine\ActionFilter\Attribute\Rule\CloseSession;
use Bitrix\Main\Provider\Params\Pager;
use Bitrix\Main\UI\PageNavigation;
use Bitrix\Tasks\V2\Infrastructure\Controller\BaseController;
use Bitrix\Tasks\V2\Internal\Access\Task\Permission;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Public\Provider\Params\TaskHistoryParams;
use Bitrix\Tasks\V2\Public\Provider\TaskHistoryProvider;

class History extends BaseController
{
	/**
	 * @ajaxAction tasks.V2.Task.History.tail
	 */
	#[CloseSession]
	public function tailAction(
		#[Permission\Read]
		Entity\Task $task,
		TaskHistoryProvider $taskHistoryProvider,
		PageNavigation $pageNavigation,
	): array
	{
		$params = new TaskHistoryParams(
			taskId: (int)$task->id,
			userId: $this->userId,
			pager: Pager::buildFromPageNavigation($pageNavigation),
			checkAccess: false,
		);

		return [
			'history' => $taskHistoryProvider->tail($params)
		];
	}
}
