<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Command\Task\Relation;

use Bitrix\Main\Error;
use Bitrix\Main\Validation\Rule\ElementsType;
use Bitrix\Main\Validation\Rule\Enum\Type;
use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Result\Result;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Copy\Config\CopyConfig;
use Bitrix\Tasks\V2\Public\Command\AbstractCommand;
use Exception;

class AddMultiTaskChildrenCommand extends AbstractCommand
{
	public function __construct(
		#[PositiveNumber]
		public readonly int $taskId,
		#[ElementsType(typeEnum: Type::Numeric)]
		public readonly array $userIds,
		public readonly CopyConfig $config,
	)
	{

	}
	protected function executeInternal(): Result
	{
		$result = new Result();

		$handler = Container::getInstance()->get(AddMultiTaskChildrenHandler::class);

		try
		{
			$tasks = $handler($this);

			return $result->setCollection($tasks);
		}
		catch (Exception $e)
		{
			return $result->addError(Error::createFromThrowable($e));
		}
	}
}
