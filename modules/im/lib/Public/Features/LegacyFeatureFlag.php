<?php
declare(strict_types=1);

namespace Bitrix\Im\Public\Features;

use Bitrix\Main\Config\Feature\AbstractFlag;

abstract class LegacyFeatureFlag extends AbstractFlag
{
	public function enabledByDefault(): bool
	{
		return LegacyFeatureFlagRegistry::isLegacyOptionEnabledForFlag(static::class);
	}
}
