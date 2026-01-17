<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Command\Task\Copy;

use Bitrix\Main\Error;
use Bitrix\Tasks\Control\Exception\TaskAddException;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Result\Result;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Copy\Config\CopyConfig;
use Bitrix\Tasks\V2\Public\Command\AbstractCommand;
use Exception;

class CopyTaskCommand extends AbstractCommand
{
	public function __construct(
		public readonly Entity\Task $task,
		public readonly CopyConfig $config,
	)
	{
	}

	protected function executeInternal(): Result
	{
		$result = new Result();

		$handler = Container::getInstance()->get(CopyTaskHandler::class);

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
