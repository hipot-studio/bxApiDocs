<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Service\User;

use Bitrix\Intranet\Public\Type\User\InitializeUserMode;

class FirstAuthorizeInitializationService
{
	private InitializeUserCommandRunner $commandRunner;

	public function __construct(
		?\Closure $commandExecutor = null,
		?UserInitializationErrorLogger $errorLogger = null,
	)
	{
		$this->commandRunner = new InitializeUserCommandRunner($commandExecutor, $errorLogger);
	}

	public function initialize(int $userId): void
	{
		$this->commandRunner->run($userId, InitializeUserMode::FirstAuthorizeFallback);
	}
}
