<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Command\User;

use Bitrix\Main\Error;
use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Result\Result;
use Bitrix\Tasks\V2\Internal\Service\OptionDictionary;
use Bitrix\Tasks\V2\Public\Command\AbstractCommand;
use Exception;

class SetUserOptionCommand extends AbstractCommand
{
	public function __construct(
		public readonly OptionDictionary $optionName,
		public readonly mixed $optionValue,
		#[PositiveNumber]
		public readonly int $userId,
	)
	{
	}

	protected function executeInternal(): Result
	{
		$result = new Result();

		$handler = Container::getInstance()->get(SetUserOptionHandler::class);

		try
		{
			$handler($this);

			return $result;
		}
		catch (Exception $e)
		{
			return $result->addError(Error::createFromThrowable($e));
		}
	}
}
