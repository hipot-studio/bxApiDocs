<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Command\Task\Tracking;

use Bitrix\Main\Error;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Entity\Task\ElapsedTime;
use Bitrix\Tasks\V2\Internal\Result\Result;
use Bitrix\Tasks\V2\Public\Command\AbstractCommand;
use Exception;

class DeleteElapsedTimeCommand extends AbstractCommand
{
	public function __construct(
		public readonly ElapsedTime $elapsedTime,
	)
	{
	}

	protected function executeInternal(): Result
	{
		$result = new Result();

		$handler = Container::getInstance()->get(DeleteElapsedTimeHandler::class);

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