<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Public\Command\User;

use Bitrix\Intranet\Internal\Entity\IntranetUser;
use Bitrix\Intranet\Internal\Service\User\InitializeUserCommandEffectService;
use Bitrix\Intranet\Public\Type\User\InitializeUserMode;

class InitializeUserCommandHandler
{
	private InitializeUserCommandEffectService $effectService;

	public function __construct(?InitializeUserCommandEffectService $effectService = null)
	{
		$this->effectService = $effectService ?? new InitializeUserCommandEffectService();
	}

	public function __invoke(InitializeUserCommand $command): IntranetUser
	{
		$intranetUser = new IntranetUser();
		$intranetUser->setUserId($command->userId);
		$intranetUser->setInitialized(true);

		$this->effectService->saveInitializedUser($intranetUser);
		$userId = $command->userId;

		if ($this->effectService->isCloud())
		{
			$this->effectService->notifyNewUser($userId);
			$this->effectService->createDemoTasks($userId);
			$this->effectService->clearUserCardCache();
		}
		else
		{
			if ($command->mode === InitializeUserMode::Standard)
			{
				$this->effectService->initializeBoxUser($userId);
			}

			$this->effectService->createDemoTasks($userId);
		}

		$this->effectService->handleAfterInitialization($userId);

		return $intranetUser;
	}
}
