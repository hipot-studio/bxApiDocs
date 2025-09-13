<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Command\Task;

use Bitrix\Main\Error;
use Bitrix\Main\Validation\Rule\AtLeastOnePropertyNotEmpty;
use Bitrix\Main\Validation\Rule\Min;
use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Update\Config\UpdateConfig;
use Bitrix\Tasks\V2\Internal\Result\Result;
use Bitrix\Tasks\V2\Public\Command\AbstractCommand;
use Exception;

#[AtLeastOnePropertyNotEmpty(fields: ['startPlanTs', 'endPlanTs', 'duration'], allowZero: true)]
class UpdatePlanCommand extends AbstractCommand
{
	public function __construct(
		#[PositiveNumber]
		public readonly int          $taskId,
		public readonly UpdateConfig $config,
		#[Min(0)]
		public readonly ?int         $startPlanTs = null,
		#[Min(0)]
		public readonly ?int         $endPlanTs = null,
		#[Min(0)]
		public readonly ?int         $duration = null,
	)
	{

	}

	protected function execute(): Result
	{
		$result = new Result();

		$consistencyResolver = Container::getInstance()->getConsistencyResolver();
		$updateService = Container::getInstance()->getUpdateService();

		$handler = new UpdatePlanHandler($consistencyResolver, $updateService);

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
