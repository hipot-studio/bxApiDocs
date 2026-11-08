<?php
declare(strict_types=1);

namespace Bitrix\BIConnector\Superset\UI\DashboardOnboarding;

enum DashboardOnboardingMode: string
{
	case Tour = 'tour';
	case EmptyDashboardCta = 'emptyDashboard';
	case None = 'none';
}
