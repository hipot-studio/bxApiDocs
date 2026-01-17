<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Command\Template;

use Bitrix\Main\Error;
use Bitrix\Main\Validation\Rule\Recursive\Validatable;
use Bitrix\Main\Validation\ValidationResult;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Result\Result;
use Bitrix\Tasks\V2\Internal\Service\Template\Action\Update\Config\UpdateConfig;
use Bitrix\Tasks\V2\Public\Command\AbstractCommand;
use Bitrix\Tasks\V2\Internal\Entity;
use Exception;

class UpdateTemplateCommand extends AbstractCommand
{
	public function __construct(
		#[Validatable]
		public readonly Entity\Template $template,
		public readonly UpdateConfig $config,
	)
	{

	}

	protected function validateInternal(): ValidationResult
	{
		return new ValidationResult();
	}

	protected function executeInternal(): Result
	{
		$result = new Result();

		$handler = Container::getInstance()->get(UpdateTemplateHandler::class);

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
