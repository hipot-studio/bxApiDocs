<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Command\Template\Task;

use Bitrix\Main\Error;
use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Result\Result;
use Bitrix\Tasks\V2\Internal\Service\Template\Task\Add\Config\AddTaskConfig;
use Bitrix\Tasks\V2\Public\Command\AbstractCommand;
use Exception;

class AddTaskCommand extends AbstractCommand
{
	public function __construct(
		#[PositiveNumber]
		public readonly int $templateId,
		public readonly Entity\Task $taskData,
		public readonly AddTaskConfig $config,
	)
	{

	}

	protected function executeInternal(): Result
	{
		$result = new Result();

		$handler = Container::getInstance()->get(AddTaskHandler::class);

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
