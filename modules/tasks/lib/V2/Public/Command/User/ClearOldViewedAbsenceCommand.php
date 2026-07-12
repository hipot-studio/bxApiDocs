<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Command\User;

use Bitrix\Main\Error;
use Bitrix\Main\Type\DateTime;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Result\Result;
use Bitrix\Tasks\V2\Public\Command\AbstractCommand;
use Exception;

class ClearOldViewedAbsenceCommand extends AbstractCommand
{
	public function __construct(
		public readonly DateTime $dateTime,
	)
	{
	}

	protected function executeInternal(): Result
	{
		$result = new Result();

		$handler = Container::getInstance()->get(ClearOldViewedAbsenceHandler::class);

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
