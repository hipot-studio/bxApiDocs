<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Service\User;

use Bitrix\Intranet\Public\Command\User\InitializeUserCommand;
use Bitrix\Intranet\Public\Type\User\InitializeUserMode;
use Bitrix\Main\Command\Exception\CommandValidationException;
use Bitrix\Main\Result;

/**
 * Runs user initialization so that a failure of the optional tasks integration
 * never breaks user creation or authorization.
 */
class InitializeUserCommandRunner
{
	private \Closure $commandExecutor;
	private UserInitializationErrorLogger $errorLogger;

	public function __construct(
		?\Closure $commandExecutor = null,
		?UserInitializationErrorLogger $errorLogger = null,
	)
	{
		$this->commandExecutor = $commandExecutor ?? static fn(InitializeUserCommand $command): Result => $command->run();
		$this->errorLogger = $errorLogger ?? new UserInitializationErrorLogger();
	}

	public function run(int $userId, InitializeUserMode $mode): void
	{
		try
		{
			$result = ($this->commandExecutor)(new InitializeUserCommand($userId, $mode));

			if (!$result->isSuccess())
			{
				$this->errorLogger->logResultErrors($result, $userId);
			}
		}
		catch (CommandValidationException)
		{
		}
		catch (\Throwable $exception)
		{
			$this->errorLogger->logThrowable($exception, $userId);
		}
	}
}
