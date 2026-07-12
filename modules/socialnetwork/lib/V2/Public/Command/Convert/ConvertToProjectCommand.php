<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Public\Command\Convert;

use Bitrix\Main\Application;
use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Result;
use Bitrix\Main\Error;
use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Socialnetwork\V2\Internal\DI\Container;
use Throwable;

class ConvertToProjectCommand extends AbstractCommand
{
	public function __construct(
		#[PositiveNumber]
		public readonly int $groupId,
		#[PositiveNumber]
		public readonly int $userId,
	)
	{

	}

	protected function execute(): Result
	{
		$handler = Container::getInstance()->get(ConvertToProjectCommandHandler::class);

		try
		{
			return $handler($this);
		}
		catch (Throwable $t)
		{
			$this->clearCache($this->groupId);

			$this->writeToLog($t);

			return (new Result())->addError(Error::createFromThrowable($t));
		}
	}

	protected function writeToLog(Throwable $t): void
	{
		Application::getInstance()->getExceptionHandler()->writeToLog($t);
	}

	protected function clearCache(int $groupId): void
	{
		global $CACHE_MANAGER;
		$CACHE_MANAGER?->ClearByTag('sonet_group_' . $groupId);
	}
}
