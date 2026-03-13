<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Public\Command\User;

use Bitrix\Main\ArgumentOutOfRangeException;
use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Validation\ValidationResult;
use Bitrix\Intranet;
use Bitrix\Intranet\Public\Service;

class InitializeUserCommand extends AbstractCommand
{
	public function __construct(public readonly int $userId)
	{
	}

	protected function execute(): Result
	{
		try
		{
			return new IntranetUserResult(
				intranetUser: (new InitializeUserCommandHandler())($this),
			);
		}
		catch (\Exception $e)
		{
			return (new IntranetUserResult())->addError(
				new Error(
					$e->getMessage(),
					$e->getCode(),
				),
			);
		}
	}

	/**
	 * @throws ArgumentOutOfRangeException
	 */
	protected function validate(): ValidationResult
	{
		$result = new ValidationResult();
		$initializeService = new Service\User\UserInitializationStatusService();

		if ($initializeService->isInitialized($this->userId))
		{
			return $result->addError(new Error('User already initialized'));
		}

		$user = new Intranet\User($this->userId);

		if ($user->isAdmin() || $user->isIntranet() || $user->isExtranet())
		{
			return $result;
		}

		return $result->addError(new Error('Invalid user'));
	}
}
