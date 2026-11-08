<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Public\Command\Activity\Settings;

use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Error;
use Bitrix\Main\LoaderException;
use CBPArgumentException;

class SaveCommand extends AbstractCommand
{
	/**
	 * @param ?\Closure $readableExpressionContextResolver How the readable form of an expression is resolved
	 *     for the saved node, handed on to the handler as it stands - the contract, and what passing none of
	 *     it costs, are described at {@see SaveCommandHandler::__construct()}.
	 */
	public function __construct(
		public readonly SaveCommandDto $data,
		private readonly ?\Closure $readableExpressionContextResolver = null,
	)
	{}

	protected function execute(): SaveCommandResult
	{
		try
		{
			$result = (new SaveCommandHandler(
				readableExpressionContextResolver: $this->readableExpressionContextResolver,
			))($this);
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
