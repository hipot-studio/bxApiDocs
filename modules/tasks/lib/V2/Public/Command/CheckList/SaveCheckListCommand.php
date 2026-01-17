<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Command\CheckList;

use Bitrix\Main\Error;
use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Result\Result;
use Bitrix\Tasks\V2\Public\Command\AbstractCommand;
use Exception;

class SaveCheckListCommand extends AbstractCommand
{
	public function __construct(
		public readonly Entity\Task $task,
		#[PositiveNumber]
		public readonly int $updatedBy,
		public readonly ?Entity\Task $taskBeforeUpdate = null,
		public readonly bool $useConsistency = false,
		public readonly bool $skipNotification = false,
	)
	{

	}

	protected function executeInternal(): Result
	{
		$result = new Result();

		$handler = Container::getInstance()->get(SaveCheckListCommandHandler::class);

		try
		{
			$entity = $handler($this);

			return $result->setObject($entity);
		}
		catch (Exception $e)
		{
			return $result->addError(Error::createFromThrowable($e));
		}
	}
}
