<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Public\Command\FullReport;

use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Timeman\V2\Internal\DI\Container;

class SendCommand extends AbstractCommand
{
	public function __construct(
		#[PositiveNumber]
		public readonly int $reportId,
		#[PositiveNumber]
		public readonly int $senderId,
	)
	{
	}

	protected function execute(): Result
	{
		$result = new Result();

		$handler = Container::getInstance()->get(SendCommandHandler::class);

		try
		{
			return $handler($this);
		}
		catch (\Exception $e)
		{
			return $result->addError(Error::createFromThrowable($e));
		}
	}
}
