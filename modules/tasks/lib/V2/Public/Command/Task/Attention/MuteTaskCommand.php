<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Command\Task\Attention;

use Bitrix\Main\Error;
use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Tasks\V2\Public\Command\AbstractCommand;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Exception\Task\UserOptionException;
use Bitrix\Tasks\V2\Internal\Result\Result;

class MuteTaskCommand extends AbstractCommand
{
	public function __construct(
		#[PositiveNumber]
		public readonly int $taskId,
		#[PositiveNumber]
		public readonly int $userId,
	)
	{

	}

	protected function executeInternal(): Result
	{
		$result = new Result();

		$handler = Container::getInstance()->get(MuteTaskHandler::class);

		try
		{
			$handler($this);

			return $result;
		}
		catch (UserOptionException $e)
		{
			return $result->addError(Error::createFromThrowable($e));
		}
	}
}
