<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Command\Task\Attention;

use Bitrix\Main\Error;
use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Tasks\V2\Public\Command\AbstractCommand;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Result\Result;
use Exception;

class SetAverageTaskPriorityCommand extends AbstractCommand
{
	public function __construct(
		#[PositiveNumber]
		public readonly int $taskId,
		#[PositiveNumber]
		public readonly int $userId,
	)
	{

	}

	protected function execute(): Result
	{
		$result = new Result();

		$consistencyResolver = Container::getInstance()->getConsistencyResolver();
		$updateService = Container::getInstance()->getUpdateService();

		$handler = new SetAverageTaskPriorityHandler(
			$consistencyResolver,
			$updateService
		);

		try
		{
			$handler($this);
		}
		catch (Exception $e)
		{
			return $result->addError(Error::createFromThrowable($e));
		}

		return $result;
	}
}