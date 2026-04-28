<?php
declare(strict_types=1);

namespace Bitrix\Disk\Public\Command\CustomServer;

use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Throwable;

class DisconnectCustomServerCommand extends AbstractCommand
{
	/**
	 * @param mixed $id
	 */
	public function __construct(
		public readonly mixed $id,
	)
	{
	}

	/**
	 * @return Result
	 */
	protected function execute(): Result
	{
		$result = new Result();

		try
		{
			$error = ServiceLocator::getInstance()->get(DisconnectCustomServerCommandHandler::class)($this);

			if ($error instanceof Error)
			{
				$result->addError($error);
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
