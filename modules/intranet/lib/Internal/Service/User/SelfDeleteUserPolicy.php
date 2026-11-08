<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Service\User;

use Bitrix\Intranet\Entity\Collection\UserCollection;
use Bitrix\Intranet\Entity\User;
use Bitrix\Intranet\Enum\InvitationStatus;
use Bitrix\Intranet\Integration\HumanResources\HrUserService;
use Bitrix\Main\ModuleManager;

final class SelfDeleteUserPolicy
{
	public function __construct(
		private readonly ?HrUserService $hrUserService = null,
		private readonly ?bool $isExtranetModuleInstalled = null,
	)
	{
	}

	public function filterActiveLocalAdmins(UserCollection $users): UserCollection
	{
		$activeAdminCandidates = $users->filter(
			static fn(User $user): bool => $user->isAdmin()
				&& $user->getInviteStatus() === InvitationStatus::ACTIVE
				&& !$user->isIntegrator()
				&& !$user->isExternal(),
		);

		if ($activeAdminCandidates->empty() || !$this->isExtranetModuleInstalled())
		{
			return $activeAdminCandidates;
		}

		$employeeIds = $this->getHrUserService()->filterEmployeesByUserIds(
			$activeAdminCandidates->getIds(),
		);
		$employeeIdMap = array_fill_keys(array_map('intval', $employeeIds), true);

		return $activeAdminCandidates->filter(
			static fn(User $user): bool => isset($employeeIdMap[$user->getId()]),
		);
	}

	private function isExtranetModuleInstalled(): bool
	{
		return $this->isExtranetModuleInstalled ?? ModuleManager::isModuleInstalled('extranet');
	}

	private function getHrUserService(): HrUserService
	{
		return $this->hrUserService ?? new HrUserService();
	}
}
