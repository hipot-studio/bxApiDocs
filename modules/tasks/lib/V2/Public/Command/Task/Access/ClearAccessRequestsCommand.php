<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Command\Task\Access;

use Bitrix\Main\Error;
use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Exception\Task\AccessRequestException;
use Bitrix\Tasks\V2\Internal\Result\Result;
use Bitrix\Tasks\V2\Public\Command\AbstractCommand;
use Exception;

class ClearAccessRequestsCommand extends AbstractCommand
{
	private const LIFETIME_TS_DEFAULT = 86400;

	public function __construct(
		#[PositiveNumber]
		public readonly int $lifeTimeTs = self::LIFETIME_TS_DEFAULT,
	)
	{

	}

	protected function executeInternal(): Result
	{
		$result = new Result();

		$handler = Container::getInstance()->get(ClearAccessRequestsHandler::class);

		try
		{
			$handler($this);
		}
		catch (Exception $e)
		{
			return $result->addError(Error::createFromThrowable($e));
		}

		return $result;
	}
}
