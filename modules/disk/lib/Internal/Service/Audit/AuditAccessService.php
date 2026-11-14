<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Audit;

use Bitrix\Main\Engine\CurrentUser;
use Closure;

final class AuditAccessService
{
	private AuditAvailabilityService $availabilityService;
	private Closure $adminResolver;

	public function __construct(
		?AuditAvailabilityService $availabilityService = null,
		?callable $adminResolver = null,
	)
	{
		$this->availabilityService = $availabilityService ?? new AuditAvailabilityService();
		$this->adminResolver = Closure::fromCallable(
			$adminResolver
			?? static fn(?CurrentUser $currentUser = null): bool => ($currentUser ?? CurrentUser::get())->isAdmin(),
		);
	}

	public function canReadSettings(?CurrentUser $currentUser = null): bool
	{
		return $this->isAdmin($currentUser) && $this->availabilityService->canView();
	}

	public function canUpdateSettings(?CurrentUser $currentUser = null): bool
	{
		return $this->isAdmin($currentUser) && $this->availabilityService->canEditSettings();
	}

	public function isAdmin(?CurrentUser $currentUser = null): bool
	{
		return (bool)($this->adminResolver)($currentUser);
	}
}
