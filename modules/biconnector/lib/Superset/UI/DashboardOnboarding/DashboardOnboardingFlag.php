<?php
declare(strict_types=1);

namespace Bitrix\BIConnector\Superset\UI\DashboardOnboarding;

/**
 * Per-user server-side flags of the report onboarding, stored as Main\UI\Spotlight view dates.
 */
enum DashboardOnboardingFlag: string
{
	case TourPassed = 'biconnector-dashboard-onboarding-tour';
	case EmptyDashboardCtaShown = 'biconnector-dashboard-onboarding-empty-dashboard-cta';
}
