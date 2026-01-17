<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Command\Task\Result;

use Bitrix\Main\Error;
use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Result\Result;
use Bitrix\Tasks\V2\Public\Command\AbstractCommand;
use Exception;

class AddResultFromMessageCommand extends AbstractCommand
{
	public function __construct(
		#[PositiveNumber]
		public readonly int $userId,
		#[PositiveNumber]
		public readonly int $messageId,
	)
	{

	}
	protected function executeInternal(): Result
	{
		$response = new Result();

		$handler = Container::getInstance()->get(AddResultFromMessageHandler::class);

		try
		{
			$result = $handler($this);

			return $response->setObject($result);
		}
		catch (Exception $e)
		{
			return $response->addError(Error::createFromThrowable($e));
		}
	}
}
