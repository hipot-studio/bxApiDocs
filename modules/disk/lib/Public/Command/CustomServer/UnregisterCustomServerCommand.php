<?php
declare(strict_types=1);

namespace Bitrix\Disk\Public\Command\CustomServer;

use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Throwable;

class UnregisterCustomServerCommand extends AbstractCommand
{
	protected function execute(): Result
	{
		$result = new Result();

		try
		{
			ServiceLocator::getInstance()->get(UnregisterCustomServerCommandHandler::class)($this);
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
