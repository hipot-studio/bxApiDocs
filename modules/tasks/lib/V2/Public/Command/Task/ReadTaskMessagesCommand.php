<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Command\Task;

use Bitrix\Main\Error;
use Bitrix\Main\Validation\Rule\Recursive\Validatable;
use Bitrix\Main\Validation\ValidationResult;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Result\Result;
use Bitrix\Tasks\V2\Public\Command\AbstractCommand;
use Throwable;

class ReadTaskMessagesCommand extends AbstractCommand
{
	public function __construct(
		#[Validatable]
		public readonly int $taskId,
		#[Validatable]
		public readonly int $userId,
	)
	{
	}

	protected function validateInternal(): ValidationResult
	{
		return new ValidationResult();
	}

	protected function executeInternal(): Result
	{
		$result = new Result();
		$handler = Container::getInstance()->get(ReadTaskMessagesHandler::class);

		try
		{
			$handler($this);

			return $result;
		}
		catch (Throwable $e)
		{
			return $result->addError(Error::createFromThrowable($e));
		}
	}
}
