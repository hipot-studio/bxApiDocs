<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Command;

use Bitrix\BizprocDesigner\Internal\Exception\CommandValidateException;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Command\CommandInterface;
use Bitrix\Main\Command\Exception\CommandException;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Web\Json;

/**
 * @implements CommandInterface<V>
 * @template V of Result
 */
abstract class AbstractCommand implements CommandInterface
{
	protected array $errors = [];
	abstract protected function execute(): Result;

	protected function validate(): bool
	{
		return true;
	}

	/**
	 * @throws ArgumentException
	 */
	public function toArray(): array
	{
		return Json::decode(Json::encode($this));
	}

	protected function beforeRun(): void
	{
	}

	protected function afterRun(): void
	{
	}

	/**
	 * @return V
	 * @throws CommandValidateException
	 * @throws CommandException
	 */
	public function run(): Result
	{
		if (!$this->validate())
		{
			throw new CommandValidateException(
				$this->getValidationErrors(),
			);
		}
		$this->beforeRun();
		try
		{
			return $this->execute();
		}
		catch (\Exception $e)
		{
			throw new CommandException($this);
		}
		finally
		{
			$this->afterRun();
		}
	}

	/**
	 * @return Error[]
	 */
	protected function getValidationErrors(): array
	{
		return $this->errors;
	}
}