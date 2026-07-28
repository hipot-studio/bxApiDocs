<?php

declare(strict_types=1);

namespace Bitrix\Intranet\User\Command;

use Bitrix\Intranet\Entity\User;
use Bitrix\Intranet\Exception\DeleteFailedException;
use Bitrix\Intranet\Exception\UpdateFailedException;
use Bitrix\Intranet\Exception\WrongIdException;
use Bitrix\Intranet\Repository\UserRepository;
use Bitrix\Intranet\Service\UserService;
use Bitrix\Intranet\User\Access\UserActionDictionary;
use Bitrix\Main\ErrorCollection;
use Bitrix\Main\ObjectNotFoundException;

class DeleteOrFireUserHandler
{
	public function __construct(
		private readonly UserRepository $userRepository,
		private readonly UserService $userService,
	)
	{
	}

	/**
	 * @throws WrongIdException
	 * @throws UpdateFailedException
	 * @throws ObjectNotFoundException
	 */
	public function __invoke(DeleteOrFireUserCommand $command): void
	{
		$user = $command->user;

		if ($this->userService->isActionAvailableForUser($user, UserActionDictionary::DELETE))
		{
			try
			{
				$this->userRepository->delete($user);
			}
			catch (DeleteFailedException)
			{
				$this->fireUser($command->user);
			}

			$this->userService->clearCache();

			return;
		}

		$this->fireUser($command->user);
	}

	/**
	 * @throws UpdateFailedException
	 */
	private function fireUser(User $user): void
	{
		$this->assertCanFire($user);
		$user->setActive(false);
		$user->setConfirmCode('');
		$this->userRepository->update($user);
	}

	/**
	 * @throws UpdateFailedException
	 */
	private function assertCanFire(User $user): void
	{
		$errors = UserStatusEventHelper::collectErrors(UserStatusEventHelper::EVENT_BEFORE_FIRE, $user);

		if (empty($errors))
		{
			return;
		}

		$errorCollection = new ErrorCollection();

		foreach ($errors as $error)
		{
			$errorCollection[] = $error;
		}

		throw new UpdateFailedException($errorCollection);
	}
}
