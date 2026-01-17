<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Task\Action\Delete\Relation;

use Bitrix\Tasks\Internals\UserOption;
use Bitrix\Tasks\V2\Internal\DI\Container;

class DeleteUserOptions
{
	public function __invoke(array $fullTaskData): void
	{
		UserOption::deleteByTaskId($fullTaskData['ID']);
		Container::getInstance()->getTaskUserOptionRepository()->invalidate($fullTaskData['ID']);
	}
}
