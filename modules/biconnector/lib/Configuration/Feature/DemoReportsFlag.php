<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Configuration\Feature;

use Bitrix\BIConnector\Configuration\Feature;
use Bitrix\Main\Config\Feature\AbstractFlag;

/**
 * The rollout switch of the demo mode of system dashboards.
 *
 * Before this class the switch was the module option bi_demo_reports, and that option has already been
 * shipped. The default of the flag therefore reads the option: a portal where it was turned on keeps the
 * demo mode without a migration, and every other portal reads as off. An explicit value set with
 * main:feature takes precedence over the option.
 *
 * The flag is asked through Configuration\Feature::isDemoReportsEnabled() and nowhere else.
 */
final class DemoReportsFlag extends AbstractFlag
{
	public function enabledByDefault(): bool
	{
		return Feature::checkFeatureOption(Feature::BI_DEMO_REPORTS);
	}
}
