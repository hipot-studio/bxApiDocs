<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Command\Template;

use Bitrix\Main\Error;
use Bitrix\Main\Validation\Rule\Recursive\Validatable;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Result\Result;
use Bitrix\Tasks\V2\Internal\Service\Template\Action\Add\Config\AddConfig;
use Bitrix\Tasks\V2\Public\Command\AbstractCommand;
use Bitrix\Tasks\V2\Internal\Entity;
use Exception;

class AddTemplateCommand extends AbstractCommand
{
	public function __construct(
		#[Validatable]
		public readonly Entity\Template $template,
		public readonly AddConfig $config,
	)
	{

	}

	protected function executeInternal(): Result
	{
		$result = new Result();

		$handler = Container::getInstance()->get(AddTemplateHandler::class);

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
