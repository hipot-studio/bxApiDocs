<?php
declare(strict_types=1);

namespace Bitrix\Disk\Public\Command\CustomServer;

use Bitrix\Disk\Internal\Enum\CustomServerTypes;
use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Throwable;

class ConnectCustomServerCommand extends AbstractCommand
{
	/**
	 * @param CustomServerTypes $type
	 * @param array $data
	 */
	public function __construct(
		public readonly CustomServerTypes $type,
		public readonly array $data,
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
			$error = ServiceLocator::getInstance()->get(ConnectCustomServerCommandHandler::class)($this);

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
