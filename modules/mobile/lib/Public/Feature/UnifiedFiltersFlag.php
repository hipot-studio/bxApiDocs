<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Public\Feature;

use Bitrix\Main\Config\Feature\AbstractFlag;

/**
 * Portal-wide switch of unified filters: turns the feature on in every mobile module at once.
 * A single module is switched by its own flag, see UnifiedFiltersModuleFlag.
 */
final class UnifiedFiltersFlag extends AbstractFlag
{
	public function enabledByDefault(): bool
	{
		return false;
	}
}
