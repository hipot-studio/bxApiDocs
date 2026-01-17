<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Command\Template;

use Bitrix\Main\Error;
use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Result\Result;
use Bitrix\Tasks\V2\Internal\Service\Template\Action\Delete\Config\DeleteConfig;
use Bitrix\Tasks\V2\Public\Command\AbstractCommand;
use Exception;

class DeleteTemplateCommand extends AbstractCommand
{
	public function __construct(
		#[PositiveNumber]
		public readonly int $templateId,
		public readonly DeleteConfig $config,
	)
	{

	}

	protected function executeInternal(): Result
	{
		$result = new Result();

		$handler = Container::getInstance()->get(DeleteTemplateHandler::class);

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
