<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Command\Template\State;

use Bitrix\Main\Error;
use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Entity\State\StateFlags;
use Bitrix\Tasks\V2\Internal\Result\Result;
use Bitrix\Tasks\V2\Public\Command\AbstractCommand;
use Exception;

class SetStateFlagsCommand extends AbstractCommand
{
	public function __construct(
		public readonly StateFlags $flags,
		#[PositiveNumber]
		public readonly int $userId,
	)
	{

	}

	protected function executeInternal(): Result
	{
		$result = new Result();

		$handler = Container::getInstance()->get(SetStateFlagsHandler::class);

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
