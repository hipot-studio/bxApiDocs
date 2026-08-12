<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Public\Command\FullReport;

use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Timeman\V2\Internal\DI\Container;

class PostponeCommand extends AbstractCommand
{
	public function __construct(
		public readonly int $userId,
		public readonly int $seconds = 3600,
	)
	{
	}

	protected function execute(): Result
	{
		$result = new Result();

		$handler = Container::getInstance()->get(PostponeCommandHandler::class);

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
