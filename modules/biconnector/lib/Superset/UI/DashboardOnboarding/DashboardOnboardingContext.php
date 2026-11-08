<?php
declare(strict_types=1);

namespace Bitrix\BIConnector\Superset\UI\DashboardOnboarding;

final class DashboardOnboardingContext
{
	public function __construct(
		public readonly int $userId,
		public readonly string $dashboardType,
		public readonly string $dashboardStatus,
		public readonly int $dashboardCreatedById,
		public readonly bool $canEdit,
		public readonly bool $isBitrixGptAvailable,
	)
	{
	}
}
