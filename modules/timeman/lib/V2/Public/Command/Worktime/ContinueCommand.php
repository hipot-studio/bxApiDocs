<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Public\Command\Worktime;

use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Timeman\V2\Internal\DI\Container;

class ContinueCommand extends AbstractCommand
{
	public function __construct(
		public readonly int $userId,
		public readonly ?int $recordId = null,
		public readonly ?string $device = null,
	)
	{
	}

	protected function execute(): Result
	{
		$result = new Result();

		$handler = Container::getInstance()->get(ContinueCommandHandler::class);

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
