<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Service\User;

use Bitrix\Intranet\Contract\Repository\UserRepository as UserRepositoryContract;
use Bitrix\Intranet\Entity\User;
use Bitrix\Intranet\Internal\Integration\Bitrix24\License\LicenseService;
use Bitrix\Intranet\Public\Type\User\SelfDeleteScenario;
use Bitrix\Intranet\Service\ServiceContainer;
use Bitrix\Intranet\Service\UserService;

final readonly class SelfDeleteScenarioResolver implements SelfDeleteScenarioResolverInterface
{
	public function __construct(
		private ?UserRepositoryContract $userRepository = null,
		private ?UserService $userService = null,
		private ?LicenseService $licenseService = null,
		private ?SelfDeleteUserPolicy $userPolicy = null,
	)
	{
	}

	public function resolveByUserId(int $userId): SelfDeleteScenario
	{
		$user = $this->getUserRepository()->getUserById($userId);

		if (!$user)
		{
			return SelfDeleteScenario::NotLastAdmin;
		}

		return $this->resolveByUser($user);
	}

	public function resolveByUser(User $user): SelfDeleteScenario
	{
		return $this->resolveLastAdminScenario($user) ?? SelfDeleteScenario::NotLastAdmin;
	}

	private function resolveLastAdminScenario(User $user): ?SelfDeleteScenario
	{
		if (!$user->isAdmin())
		{
			return null;
		}

		if (!$this->getUserRepository()->hasMoreThanOneActiveUser())
		{
			return SelfDeleteScenario::LastUserAdmin;
		}

		if ($this->getActiveAdminCount() <= 1)
		{
			return $this->isFreeLicense()
				? SelfDeleteScenario::LastAdminFreeLicense
				: SelfDeleteScenario::LastAdminPayedLicense;
		}

		return null;
	}

	private function isFreeLicense(): bool
	{
		return $this->getLicenseService()->isCloud()
			&& $this->getLicenseService()->isFree();
	}

	private function getActiveAdminCount(): int
	{
		$adminIds = $this->getUserService()->getAdminUserIds();

		if (empty($adminIds))
		{
			return 0;
		}

		$activeLocalAdmins = $this->getUserPolicy()->filterActiveLocalAdmins(
			$this->getUserRepository()->findUsersByIds($adminIds),
		);

		return $activeLocalAdmins->count();
	}

	private function getUserRepository(): UserRepositoryContract
	{
		return $this->userRepository ?? ServiceContainer::getInstance()->userRepository();
	}

	private function getUserService(): UserService
	{
		return $this->userService ?? ServiceContainer::getInstance()->getUserService();
	}

	private function getLicenseService(): LicenseService
	{
		return $this->licenseService ?? new LicenseService();
	}

	private function getUserPolicy(): SelfDeleteUserPolicy
	{
		return $this->userPolicy ?? new SelfDeleteUserPolicy();
	}
}
