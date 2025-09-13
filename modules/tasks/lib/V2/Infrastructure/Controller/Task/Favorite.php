<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Infrastructure\Controller\Task;

use Bitrix\Tasks\V2\Internal\Access\Task\Permission;
use Bitrix\Tasks\V2\Public\Command\Task\Favorite\AddFavoriteCommand;
use Bitrix\Tasks\V2\Public\Command\Task\Favorite\DeleteFavoriteCommand;
use Bitrix\Tasks\V2\Infrastructure\Controller\BaseController;
use Bitrix\Tasks\V2\Internal\Entity;

class Favorite extends BaseController
{
	/**
	 * @ajaxAction tasks.V2.Task.Favorite.add
	 */
	public function addAction(
		#[Permission\Read] Entity\Task $task,
	): ?bool
	{
		$result = (new AddFavoriteCommand(
			taskId: $task->getId(),
			userId: $this->userId,
		))->run();

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return true;
	}

	/**
	 * @ajaxAction tasks.V2.Task.Favorite.delete
	 */
	public function deleteAction(
		#[Permission\Read] Entity\Task $task,
	): ?bool
	{
		$result = (new DeleteFavoriteCommand(
			taskId: $task->getId(),
			userId: $this->userId,
		))->run();

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return true;
	}
}