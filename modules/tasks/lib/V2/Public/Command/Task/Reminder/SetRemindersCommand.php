<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Command\Task\Reminder;

use Bitrix\Main\Error;
use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Main\Validation\ValidationResult;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Entity\Task\ReminderCollection;
use Bitrix\Tasks\V2\Internal\Result\Result;
use Bitrix\Tasks\V2\Public\Command\AbstractCommand;
use Exception;

class SetRemindersCommand extends AbstractCommand
{
	public function __construct(
		#[PositiveNumber]
		public readonly int $userId,
		#[PositiveNumber]
		public readonly int $taskId,
		public readonly ReminderCollection $reminders,
		public readonly bool $useConsistency = false,
	)
	{
	}

	protected function validateInternal(): ValidationResult
	{
		$validationResult = parent::validateInternal();
		if (!$validationResult->isSuccess())
		{
			return $validationResult;
		}

		if ($this->reminders->isEmpty())
		{
			return $validationResult;
		}

		$reminders = $this->reminders->cloneWith(['userId' => $this->userId, 'taskId' => $this->taskId]);

		return Container::getInstance()->getValidationService()->validate($reminders);
	}

	protected function executeInternal(): Result
	{
		$result = new Result();

		$handler = Container::getInstance()->get(SetRemindersHandler::class);

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
