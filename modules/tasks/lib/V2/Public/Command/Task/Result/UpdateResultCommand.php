<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Command\Task\Result;

use Bitrix\Main\Error;
use Bitrix\Tasks\V2\Public\Command\AbstractCommand;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Entity;

use Bitrix\Tasks\V2\Internal\Result\Result;
use Exception;

class UpdateResultCommand extends AbstractCommand
{
	public function __construct(
		public readonly Entity\Result $result,
		public readonly int           $userId,
	)
	{
	}

	protected function execute(): Result
	{
		$response = new Result();

		try
		{
			$resultService = Container::getInstance()->getResultService();
			$consistencyResolver = Container::getInstance()->getConsistencyResolver();
			$handler = new UpdateResultHandler($resultService, $consistencyResolver);
			$object = $handler($this);

			return $response->setObject($object);
		}
		catch (Exception $e)
		{
			return $response->addError(Error::createFromThrowable($e));
		}
	}

	public function toArray(): array
	{
		return [
			'result' => $this->result->toArray(),
			'userId' => $this->userId,
		];
	}
}
