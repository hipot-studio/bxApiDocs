<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Public\Command\Report;

use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Timeman\V2\Internal\DI\Container;

class UpsertCommand extends AbstractCommand
{
	public function __construct(
		public readonly int $recordId,
		public readonly int $userId,
		public readonly string $reportText,
	)
	{
	}

	protected function execute(): Result
	{
		$result = new Result();

		$handler = Container::getInstance()->get(UpsertCommandHandler::class);

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
