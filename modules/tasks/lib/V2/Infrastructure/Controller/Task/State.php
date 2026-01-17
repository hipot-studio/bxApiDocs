<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Infrastructure\Controller\Task;

use Bitrix\Tasks\V2\Infrastructure\Controller\BaseController;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Public\Command\Task\State\SetStateCommand;

class State extends BaseController
{
	/**
	 * @ajaxAction tasks.V2.Task.State.set
	 */
	public function setAction(
		Entity\Task\State $state,
	): ?bool
	{
		$result = (new SetStateCommand(
			state: $state,
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
