<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Command\CheckList;

use Bitrix\Main\Error;
use Bitrix\Main\Validation\Rule\ElementsType;
use Bitrix\Main\Validation\Rule\Enum\Type;
use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Result\Result;
use Bitrix\Tasks\V2\Public\Command\AbstractCommand;
use Exception;

class RenewCheckListItemsCommand extends AbstractCommand
{
	public function __construct(
		#[ElementsType(typeEnum: Type::Numeric)]
		public readonly array $ids,
		#[PositiveNumber]
		public readonly int $userId,
	)
	{
	}

	protected function executeInternal(): Result
	{
		$result = new Result();

		$handler = Container::getInstance()->get(RenewCheckListItemsHandler::class);

		try
		{
			$entity = $handler($this);

			return $result->setData($entity->getEntities());
		}
		catch (Exception $e)
		{
			return $result->addError(Error::createFromThrowable($e));
		}
	}
}

