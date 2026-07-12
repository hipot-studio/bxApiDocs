<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Public\Command;

use Bitrix\Im\V2\Error;
use Bitrix\Im\V2\Result;
use Bitrix\Main\Validation\ValidationResult;

abstract class AbstractCommand extends \Bitrix\Main\Command\AbstractCommand
{
	protected bool $isPermissionCheckEnabled = true;

	public function disablePermissionCheck(): static
	{
		$this->isPermissionCheckEnabled = false;

		return $this;
	}

	public function enablePermissionCheck(): static
	{
		$this->isPermissionCheckEnabled = true;

		return $this;
	}

	abstract protected function executeInternal(): Result;

	protected function validateInternal(): ValidationResult
	{
		return parent::validate();
	}

	protected function execute(): Result
	{
		try
		{
			return $this->executeInternal();
		}
		catch (\Throwable $t)
		{
			return (new Result())->addError(Error::createFromThrowable($t));
		}
	}

	protected function validate(): ValidationResult
	{
		return $this->validateInternal();
	}
}
