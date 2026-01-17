<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Command\Rest\Task\Chat;

use Bitrix\Main\Error;
use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Main\Validation\Rule\Recursive\Validatable;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Integration\Im;
use Bitrix\Tasks\V2\Internal\Result\Result;
use Bitrix\Tasks\V2\Public\Command\AbstractCommand;
use Exception;

class SendMessageCommand extends AbstractCommand
{
	public function __construct(
		#[PositiveNumber]
		public readonly int $taskId,
		#[PositiveNumber]
		public readonly int $userId,
		#[Validatable]
		public readonly Im\Entity\Message $message,
	)
	{
	}


	protected function executeInternal(): Result
	{
		$handler = Container::getInstance()->get(SendMessageHandler::class);

		try
		{
			return $handler($this);
		}
		catch (Exception $e)
		{
			$result = new Result();
			return $result->addError(Error::createFromThrowable($e));
		}
	}
}