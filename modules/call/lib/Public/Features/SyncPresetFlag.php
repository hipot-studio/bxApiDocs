<?php

declare(strict_types=1);

namespace Bitrix\Call\Public\Features;

use Bitrix\Main\Config\Feature\AbstractFlag;

/**
 * Sync preset.
 */
final class SyncPresetFlag extends AbstractFlag
{
	public function enabledByDefault(): bool
	{
		return Registry::isLegacyOptionEnabledForFlag(self::class);
	}
}
