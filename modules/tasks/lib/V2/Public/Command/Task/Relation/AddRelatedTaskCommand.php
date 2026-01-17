<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Command\Task\Relation;

use Bitrix\Main\Error;
use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Result\Result;
use Bitrix\Tasks\V2\Public\Command\AbstractCommand;
use Exception;

class AddRelatedTaskCommand extends AbstractCommand
{
	public function __construct(
		#[PositiveNumber]
		public readonly int $taskId,
		#[PositiveNumber]
		public readonly int $relatedTaskId,
		#[PositiveNumber]
		public readonly int $userId,
		public readonly bool $useConsistency = false,
	)
	{
	}

	protected function executeInternal(): Result
	{
		$result = new Result();

		$handler = Container::getInstance()->get(AddRelatedTaskHandler::class);

		try
		{
			$task = $handler($this);

			return $result->setObject($task);
		}
		catch (Exception $e)
		{
			return $result->addError(Error::createFromThrowable($e));
		}
	}
}
