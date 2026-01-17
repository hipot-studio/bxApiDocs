<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Public\Command\Activity\Settings;

use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Error;
use Bitrix\Main\LoaderException;
use CBPArgumentException;

class SaveCommand extends AbstractCommand
{
	public function __construct(public readonly SaveCommandDto $data)
	{}

	protected function execute(): SaveCommandResult
	{
		try
		{
			$result = (new SaveCommandHandler())($this);
		}
		catch (LoaderException | CBPArgumentException $e)
		{
			return (new SaveCommandResult())->addError(new Error($e->getMessage(), $e->getCode()));
		}

		if (!$result->isSuccess())
		{
			return (new SaveCommandResult())->addErrors($result->getErrors());
		}

		return (new SaveCommandResult())
			->setSettings($result->getSettings())
			->setVariables($result->getVariables())
			->setParameters($result->getParameters());
	}
}
