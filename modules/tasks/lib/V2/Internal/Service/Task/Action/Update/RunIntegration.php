<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Task\Action\Update;

use Bitrix\Im\V2\Service\Locator;
use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Update\Trait\ConfigTrait;
use Bitrix\Tasks\V2\Internal\Service\Task\Trait\TaskTrait;
use Bitrix\Tasks\Internals\TaskObject;

class RunIntegration
{
	use TaskTrait;
	use ConfigTrait;

	public function __invoke(array $fields, TaskObject $taskBeforeUpdate): void
	{
		$application = Application::getInstance();

		if (Loader::includeModule('im'))
		{
			$task = $this->getTaskObject($taskBeforeUpdate->getId());
			$application && $application->addBackgroundJob(
				function () use ($task) {
					Locator::getMessenger()->updateTask($task);
				}
			);
		}
	}
}
