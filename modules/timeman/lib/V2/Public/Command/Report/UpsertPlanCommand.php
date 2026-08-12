<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Public\Command\Report;

use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Timeman\V2\Internal\DI\Container;

class UpsertPlanCommand extends AbstractCommand
{
	public function __construct(
		public readonly int $userId,
		public readonly string $planText,
		public readonly string $planType,
	)
	{
	}

	protected function execute(): Result
	{
		$result = new Result();

		$handler = Container::getInstance()->get(UpsertPlanCommandHandler::class);

		try
		{
			return $handler($this);
		}
		catch (\Throwable $e)
		{
			return $result->addError(Error::createFromThrowable($e));
		}
	}
}
