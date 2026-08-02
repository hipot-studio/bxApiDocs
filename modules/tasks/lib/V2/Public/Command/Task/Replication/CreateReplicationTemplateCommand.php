<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Command\Task\Replication;

use Bitrix\Main\Error;
use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Main\Validation\Rule\Recursive\Validatable;
use Bitrix\Main\Validation\ValidationResult;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Result\Result;
use Bitrix\Tasks\V2\Public\Command\AbstractCommand;
use Bitrix\Tasks\V2\Internal\Entity;
use Exception;

class CreateReplicationTemplateCommand extends AbstractCommand
{
	public function __construct(
		#[Validatable]
		public readonly Entity\Task $task,
		#[PositiveNumber]
		public readonly int $userId,
	)
	{
	}

	protected function executeInternal(): Result
	{
		$result = new Result();

		$handler = Container::getInstance()->get(CreateReplicationTemplateHandler::class);

		try
		{
			$id = $handler($this);

			return $result->setId($id);
		}
		catch (Exception $e)
		{
			return $result->addError(Error::createFromThrowable($e));
		}
	}

	protected function validateInternal(): ValidationResult
	{
		$validationResult = parent::validateInternal();
		if (!$validationResult->isSuccess())
		{
			return $validationResult;
		}

		if ((int)$this->task->id <= 0)
		{
			$validationResult->addError(new Error('Task id must be positive'));
		}

		if ($this->task->replicateParams === null)
		{
			$validationResult->addError(new Error('Property "replicateParams" must be set'));
		}

		return $validationResult;
	}
}
