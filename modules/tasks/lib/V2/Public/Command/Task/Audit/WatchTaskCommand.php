<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Command\Task\Audit;

use Bitrix\Main\Error;
use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Tasks\V2\Public\Command\AbstractCommand;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Result\Result;
use Exception;

class WatchTaskCommand extends AbstractCommand
{
	public function __construct(
		#[PositiveNumber]
		public readonly int $taskId,
		#[PositiveNumber]
		public readonly int $userId,
		#[PositiveNumber]
		public readonly int $auditorId,
		public readonly bool $skipNotification = false,
		public readonly bool $useConsistency = false,
	)
	{

	}

	protected function executeInternal(): Result
	{
		$result = new Result();

		$handler = Container::getInstance()->get(WatchTaskHandler::class);

		try
		{
			$handler($this);

			return $result;
		}
		catch (Exception $e)
		{
			return $result->addError(Error::createFromThrowable($e));
		}
	}
}
