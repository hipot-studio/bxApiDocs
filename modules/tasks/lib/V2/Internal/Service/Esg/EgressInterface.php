<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Esg;

use Bitrix\Tasks\V2\Public\Command\AbstractCommand;
use Bitrix\Tasks\V2\Public\Command\Task\AddTaskCommand;
use Bitrix\Tasks\V2\Internal\Entity\Task;
use Bitrix\Tasks\V2\Public\Command\Task\UpdateTaskCommand;

interface EgressInterface
{
	public function process(AbstractCommand $command): void;
	public function processUserFields(UpdateTaskCommand $command): void;
	public function processAddTaskCommand(AddTaskCommand $command): Task;
	public function createChatForExistingTask(Task $task): Task;
}
