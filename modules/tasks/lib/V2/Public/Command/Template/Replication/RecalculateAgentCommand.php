<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Command\Template\Replication;

use Bitrix\Main\Error;
use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Result\Result;
use Bitrix\Tasks\V2\Public\Command\AbstractCommand;
use Throwable;

class RecalculateAgentCommand extends AbstractCommand
{
	public function __construct(
		#[PositiveNumber]
		public readonly int $templateId,
	)
	{
	}

	protected function executeInternal(): Result
	{
		$result = new Result();

		$handler = Container::getInstance()->get(RecalculateAgentHandler::class);

		try
		{
			$handler($this);

			return $result->setId($this->templateId);
		}
		catch (Throwable $t)
		{
			return $result->addError(Error::createFromThrowable($t));
		}
	}
}
