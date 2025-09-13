<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Command\Task\Reminder;

use Bitrix\Main\Error;
use Bitrix\Main\Validation\Rule\Recursive\Validatable;
use Bitrix\Main\Validation\ValidationResult;
use Bitrix\Tasks\Internals\Attribute\PositiveNumber;
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
	)
	{
	}

	protected function validate(): ValidationResult
	{
		$validationResult = parent::validate();
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

	protected function execute(): Result
	{
		$result = new Result();

		$reminderService = Container::getInstance()->getReminderService();
		$reminderRepository = Container::getInstance()->getReminderRepository();
		$consistencyResolver = Container::getInstance()->getConsistencyResolver();

		$handler = new SetRemindersHandler(
			reminderService: $reminderService,
			reminderRepository: $reminderRepository,
			consistencyResolver: $consistencyResolver
		);

		try
		{
			$handler($this);
		}
		catch (Exception $e)
		{
			return $result->addError(Error::createFromThrowable($e));
		}

		return $result;
	}
}