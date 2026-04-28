<?php
declare(strict_types=1);

namespace Bitrix\Disk\Public\Command;

use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Throwable;

class UnregisterCloudClientCommand extends AbstractCommand
{
	protected function execute(): Result
	{
		$result = new Result();

		try
		{
			$errors = ServiceLocator::getInstance()->get(UnregisterCloudClientCommandHandler::class)($this);

			if (!empty($errors))
			{
				$result->addErrors($errors);
			}
		}
		catch (Throwable $exception)
		{
			// TODO log?
			$result->addError(new Error(
				message: $exception->getMessage(),
				code: $exception->getCode(),
			));
		}

		return $result;
	}
}
