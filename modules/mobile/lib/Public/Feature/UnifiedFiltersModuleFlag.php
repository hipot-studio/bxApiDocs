<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Public\Feature;

use Bitrix\Main\Config\Feature;
use Bitrix\Main\Config\Feature\AbstractFlag;

/**
 * Unified filters flag of a single mobile module. A module with no value of its own follows the
 * portal-wide UnifiedFiltersFlag; an own value wins over it, off as well as on, so one module can
 * be switched off while the portal keeps the feature on.
 */
abstract class UnifiedFiltersModuleFlag extends AbstractFlag
{
	public function enabledByDefault(): bool
	{
		return Feature::isEnabled(UnifiedFiltersFlag::class);
	}
}
