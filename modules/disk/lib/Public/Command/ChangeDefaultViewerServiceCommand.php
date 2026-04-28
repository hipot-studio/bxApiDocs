<?php
declare(strict_types=1);

namespace Bitrix\Disk\Public\Command;

use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Throwable;

class ChangeDefaultViewerServiceCommand extends AbstractCommand
{
	/**
	 * @param string $code
	 */
	public function __construct(
		public readonly string $code,
	)
	{
	}

	protected function execute(): Result
	{
		$result = new Result();

		try
		{
			ServiceLocator::getInstance()->get(ChangeDefaultViewerServiceCommandHandler::class)($this);
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
