<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Public\Service\AnnualSummary;

use Bitrix\Intranet\Internal\Config\Feature\AnnualSummaryAdminFlag;
use Bitrix\Main\AccessDeniedException;
use Bitrix\Main\Config\Feature;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\ModuleManager;

final class AdminAccess
{
	public function isAllowed(): bool
	{
		return ($this->isFeatureEnabled() || ModuleManager::isModuleInstalled('bitrix24'))
			&& CurrentUser::get()->isAdmin();
	}

	public function assertAllowed(): void
	{
		if (!$this->isAllowed())
		{
			throw new AccessDeniedException();
		}
	}

	public function isFeatureEnabled(): bool
	{
		return Feature::isEnabled(AnnualSummaryAdminFlag::class);
	}
}
