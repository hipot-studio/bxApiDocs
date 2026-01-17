<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Command\Task\Stakeholder;

use Bitrix\Main\Error;
use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Tasks\Onboarding\Validation\Rule\ArrayOfPositiveNumbers;
use Bitrix\Tasks\V2\Public\Command\AbstractCommand;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Update\Config\UpdateConfig;
use Bitrix\Tasks\V2\Internal\Result\Result;
use Exception;

class AddAuditorsCommand extends AbstractCommand
{
	public function __construct(
		#[PositiveNumber]
		public readonly int $taskId,
		#[ArrayOfPositiveNumbers]
		public readonly array $auditorIds,
		public readonly UpdateConfig $config,
	)
	{

	}

	protected function executeInternal(): Result
	{
		$result = new Result();

		$handler = Container::getInstance()->get(AddAuditorsHandler::class);

		try
		{
			$task = $handler($this);

			return $result->setObject($task);
		}
		catch (Exception $e)
		{
			return $result->addError(Error::createFromThrowable($e));
		}
	}
}
