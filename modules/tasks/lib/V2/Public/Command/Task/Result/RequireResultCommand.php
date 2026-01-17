<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Command\Task\Result;

use Bitrix\Main\Error;
use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Tasks\V2\Public\Command\AbstractCommand;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Result\Result;
use Exception;

class RequireResultCommand extends AbstractCommand
{
	public function __construct(
		#[PositiveNumber]
		public readonly int $taskId,
		#[PositiveNumber]
		public readonly int $userId,
		public readonly bool $require = true,
		public readonly bool $useConsistency = false,
	)
	{
	}

	protected function executeInternal(): Result
	{
		$response = new Result();

		$resultService = Container::getInstance()->getResultService();
		$handler = new RequireResultHandler($resultService);

		try
		{
			$task = $handler($this);

			return $response->setObject($task);
		}
		catch (Exception $e)
		{
			return $response->addError(Error::createFromThrowable($e));
		}
	}
}
