<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Task\Action\Update;

use Bitrix\Im\V2\Service\Locator;
use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Bitrix\Tasks\V2\Internal\Entity\Task;
use Bitrix\Tasks\V2\Internal\Integration\Im\Factory\TaskItemFactory;
use Bitrix\Tasks\V2\Internal\Service\Task\Trait\TaskTrait;

class RunIntegration
{
	use TaskTrait;

	public function __invoke(Task $taskAfterUpdate): void
	{
		$application = Application::getInstance();

		if ($application && Loader::includeModule('im'))
		{
			$taskItemFactory = new TaskItemFactory();
			$taskItem = $taskItemFactory->createFromTask($taskAfterUpdate);

			$application->addBackgroundJob(
				static function () use ($taskItem) {
					Locator::getMessenger()->updateTaskFromTaskItem($taskItem);
				}
			);
		}
	}
}
