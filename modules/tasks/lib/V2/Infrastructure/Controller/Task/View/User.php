<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Infrastructure\Controller\Task\View;

use Bitrix\Main\Provider\Params\Pager;
use Bitrix\Main\UI\PageNavigation;
use Bitrix\Tasks\V2\Infrastructure\Controller\BaseController;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Access\Task\Permission;
use Bitrix\Tasks\V2\Public\Provider\Params\Task\View\ViewedUserParams;
use Bitrix\Tasks\V2\Public\Provider\Task\View\ViewedUserProvider;

class User extends BaseController
{
	/**
	 * @ajaxAction tasks.V2.Task.View.User.tail
	 */
	public function tailAction(
		#[Permission\Read]
		Entity\Task $task,
		ViewedUserProvider $viewedUserProvider,
		PageNavigation $pageNavigation,
		bool $withCount = false
	): array
	{
		$params = new ViewedUserParams(
			taskId: (int)$task->id,
			userId: $this->userId,
			pager: Pager::buildFromPageNavigation($pageNavigation),
			checkAccess: false,
		);

		$response = [
			'users' => $viewedUserProvider->tail($params),
		];

		if ($withCount)
		{
			$response['viewsCount'] = $viewedUserProvider->getCount($params);
		}

		return $response;
	}

	/**
	 * @ajaxAction tasks.V2.Task.View.User.count
	 */
	public function countAction(
		#[Permission\Read]
		Entity\Task $task,
		ViewedUserProvider $viewedUserProvider,
	): array
	{
		$params = new ViewedUserParams(
			taskId: (int)$task->id,
			userId: $this->userId,
			checkAccess: false,
		);

		return ['viewsCount' => $viewedUserProvider->getCount($params)];
	}
}
