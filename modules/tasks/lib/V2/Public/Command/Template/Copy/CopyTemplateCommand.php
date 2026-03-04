<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Command\Template\Copy;

use Bitrix\Main\Error;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Result\Result;
use Bitrix\Tasks\V2\Internal\Service\Template\Action\Copy\Config\CopyConfig;
use Bitrix\Tasks\V2\Public\Command\AbstractCommand;
use Exception;

class CopyTemplateCommand extends AbstractCommand
{
	public function __construct(
		public readonly Entity\Template $templateData,
		public readonly CopyConfig $config,
	)
	{

	}

	protected function executeInternal(): Result
	{
		$result = new Result();

		$handler = Container::getInstance()->get(CopyTemplateHandler::class);

		try
		{
			$task = $handler($this);

			return $result->setObject($task);
		}
		catch (Exception $e)
		{
			return $result->addError(Error::createFromThrowable($e));
		}
	}
}
