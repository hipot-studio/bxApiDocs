<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Command\Task\Tracking;

use Bitrix\Main\Error;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Entity\Task\ElapsedTime;
use Bitrix\Tasks\V2\Public\Command\AbstractCommand;
use Bitrix\Tasks\V2\Internal\Result\Result;
use Exception;

class UpdateElapsedTimeCommand extends AbstractCommand
{
	public function __construct(
		public readonly ElapsedTime $elapsedTime,
	)
	{
	}

    protected function executeInternal(): Result
    {
		$result = new Result();

		$handler = Container::getInstance()->get(UpdateElapsedTimeHandler::class);

		try
		{
			$elapsedTime = $handler($this);

			return $result->setObject($elapsedTime);
		}
		catch (Exception $e)
		{
			return $result->addError(Error::createFromThrowable($e));
		}
    }
}
