<?php

declare(strict_types=1);

namespace Bitrix\Intranet\User\Command;

use Bitrix\Intranet\Entity\User;
use Bitrix\Intranet\Exception\UpdateFailedException;
use Bitrix\Intranet\Exception\WrongIdException;
use Bitrix\Intranet\Repository\UserRepository;
use Bitrix\Intranet\Service\ServiceContainer;
use Bitrix\Intranet\User\Access\UserActionDictionary;
use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Error;
use Bitrix\Main\Event;
use Bitrix\Main\ObjectNotFoundException;
use Bitrix\Main\Result;

/**
 * Delete user if invited
 * Delete user if awaiting
 * Fire user if active
 * Fire user if we can't delete
 */
class DeleteOrFireUserCommand extends AbstractCommand
{
	private ?User $firedUser = null;
	private bool $wasIntegrator = false;

	public function __construct(
		public readonly User $user,
	)
	{
	}

	protected function execute(): Result
	{
		$result = new Result();

		$this->wasIntegrator = $this->user->isIntegrator();

		$userService = ServiceContainer::getInstance()->getUserService();
		$isActionAvailable = $userService->isActionAvailableForUser($this->user, UserActionDictionary::FIRE)
			|| $userService->isActionAvailableForUser($this->user, UserActionDictionary::DELETE);

		if (!$isActionAvailable)
		{
			return $result->addError(new Error('User already fired'));
		}

		try
		{
			$userRepository = ServiceContainer::getInstance()->userRepository();
			$handler = new DeleteOrFireUserHandler($userRepository, $userService);
			$handler($this);
			$this->firedUser = $this->resolveFiredUser($userRepository);

			return $result;
		}
		catch (UpdateFailedException $e)
		{
			return $result->addErrors($e->getErrors());
		}
		catch (WrongIdException)
		{
			return $result->addError(new Error('Wrong user id'));
		}
		catch (ObjectNotFoundException $e)
		{
			return $result->addError(new Error($e->getMessage()));
		}
	}

	public function toArray(): array
	{
		return [
			'user' => $this->user->toArray(),
		];
	}

	protected function afterRun(): void
	{
		if ($this->firedUser && $this->wasIntegrator)
		{
			(new Event('intranet', 'onIntegratorUserFired', [
				'user' => $this->firedUser,
			]))->send();
		}
	}

	private function resolveFiredUser(UserRepository $userRepository): ?User
	{
		$userId = (int)$this->user->getId();

		if ($userId <= 0)
		{
			return null;
		}

		$updatedUser = $userRepository->getUserById($userId);

		if (!$updatedUser || $updatedUser->getActive() !== false)
		{
			return null;
		}

		return $updatedUser;
	}
}
