<?php

declare(strict_types=1);

namespace Bitrix\Intranet\User\Command;

use Bitrix\Intranet\Entity\User;
use Bitrix\Intranet\Exception\UpdateFailedException;
use Bitrix\Intranet\Service\ServiceContainer;
use Bitrix\Intranet\User\Access\UserActionDictionary;
use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Error;
use Bitrix\Main\Event;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;

class FireUserCommand extends AbstractCommand
{
	private bool $isSuccess = false;
	private bool $wasIntegrator = false;

	public function __construct(
		public readonly User $user,
	)
	{
	}

	protected function beforeRun(): ?Result
	{
		$this->wasIntegrator = $this->user->isIntegrator();

		$isActionAvailable = ServiceContainer::getInstance()
			->getUserService()
			->isActionAvailableForUser($this->user, UserActionDictionary::FIRE)
		;

		if (!$isActionAvailable)
		{
			return (new Result())->addError(new Error('User already fired'));
		}

		return null;
	}

	protected function execute(): Result
	{
		$result = new Result();

		try
		{
			$userRepository = ServiceContainer::getInstance()->userRepository();
			$userService = ServiceContainer::getInstance()->getUserService();
			$handler = new FireUserHandler($userRepository, $userService);
			$handler($this);
			$this->isSuccess = true;

			return $result;
		}
		catch (UpdateFailedException $exception)
		{
			if (trim($exception->getMessage()) === 'FIRST_ADMIN_UPDATE_FORBIDDEN')
			{
				return $result->addError(new Error(
					Loc::getMessage('INTRANET_USER_COMMAND_FIRE_FIRST_ADMIN_UPDATE_FORBIDDEN_ERROR'),
					'FIRST_ADMIN_UPDATE_FORBIDDEN'),
				);
			}

			return $result->addErrors($exception->getErrors());
		}
	}

	protected function afterRun(): void
	{
		if ($this->isSuccess && $this->wasIntegrator)
		{
			(new Event('intranet', 'onIntegratorUserFired', [
				'user' => $this->user,
			]))->send();
		}
	}

	public function toArray(): array
	{
		return [
			'user' => $this->user->toArray(),
		];
	}
}
