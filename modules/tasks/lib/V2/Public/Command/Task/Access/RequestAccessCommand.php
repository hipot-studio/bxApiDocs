<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Command\Task\Access;

use Bitrix\Main\Error;
use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Exception\Task\AccessRequestException;
use Bitrix\Tasks\V2\Internal\Result\Result;
use Bitrix\Tasks\V2\Public\Command\AbstractCommand;

class RequestAccessCommand extends AbstractCommand
{
	public function __construct(
		#[PositiveNumber]
		public readonly int $userId,
		#[PositiveNumber]
		public readonly int $taskId,
	)
	{

	}

	protected function executeInternal(): Result
	{
		$result = new Result();

		$handler = Container::getInstance()->get(RequestAccessHandler::class);

		try
		{
			$accessRequest = $handler($this);
		}
		catch (AccessRequestException $e)
		{
			return $result->addError(Error::createFromThrowable($e));
		}

		return $result->setObject($accessRequest);
	}
}
