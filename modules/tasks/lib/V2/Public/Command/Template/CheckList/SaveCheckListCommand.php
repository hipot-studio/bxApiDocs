<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Command\Template\CheckList;

use Bitrix\Main\Error;
use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Public\Command\AbstractCommand;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Result\Result;
use Exception;

class SaveCheckListCommand extends AbstractCommand
{
	public function __construct(
		public readonly Entity\Template $template,
		#[PositiveNumber]
		public readonly int $updatedBy,
		public readonly ?Entity\Template $taskBeforeUpdate = null,
	)
	{
	}

	protected function executeInternal(): Result
	{
		$result = new Result();

		$handler = Container::getInstance()->get(SaveCheckListCommandHandler::class);

		try
		{
			$collection = $handler($this);

			return $result->setData($collection->toArray());
		}
		catch (Exception $e)
		{
			return $result->addError(Error::createFromThrowable($e));
		}
	}
}
