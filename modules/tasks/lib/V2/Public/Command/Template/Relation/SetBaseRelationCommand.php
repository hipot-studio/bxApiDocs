<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Command\Template\Relation;

use Bitrix\Main\Error;
use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Result\Result;
use Bitrix\Tasks\V2\Public\Command\AbstractCommand;
use Exception;

class SetBaseRelationCommand extends AbstractCommand
{
	public function __construct(
		#[PositiveNumber]
		public readonly int $templateId,
		#[PositiveNumber]
		public readonly int $userId,
		#[PositiveNumber]
		public readonly int $baseTemplateId,
	)
	{

	}
	protected function executeInternal(): Result
	{
		$result = new Result();

		$handler = Container::getInstance()->get(SetBaseRelationHandler::class);

		try
		{
			$template = $handler($this);

			return $result->setObject($template);
		}
		catch (Exception $e)
		{
			return $result->addError(Error::createFromThrowable($e));
		}
	}
}
