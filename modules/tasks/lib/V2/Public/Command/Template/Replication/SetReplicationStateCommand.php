<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Command\Template\Replication;

use Bitrix\Main\Error;
use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Main\Validation\ValidationResult;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Entity\Template;
use Bitrix\Tasks\V2\Internal\Result\Result;
use Bitrix\Tasks\V2\Public\Command\AbstractCommand;
use Exception;

class SetReplicationStateCommand extends AbstractCommand
{
	public function __construct(
		public readonly Template $template,
		#[PositiveNumber]
		public readonly int $userId,
	)
	{
	}

	protected function executeInternal(): Result
	{
		$result = new Result();

		$handler = Container::getInstance()->get(SetReplicationStateHandler::class);

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

	protected function validateInternal(): ValidationResult
	{
		$validationResult = parent::validateInternal();
		if (!$validationResult->isSuccess())
		{
			return $validationResult;
		}

		if ((int)$this->template->id <= 0)
		{
			$validationResult->addError(new Error('Template id must be positive'));
		}

		if ($this->template->replicate === null)
		{
			$validationResult->addError(new Error('Property "replicate" must be set'));
		}

		return $validationResult;
	}
}
