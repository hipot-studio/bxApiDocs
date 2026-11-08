<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Integration\Superset\Repository;

enum DashboardGroupListMode
{
	case Default;
	case RootWithPromotedDashboards;
	case FolderWithExcludedDashboards;
}
