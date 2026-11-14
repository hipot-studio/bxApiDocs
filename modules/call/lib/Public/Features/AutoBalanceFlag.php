<?php

declare(strict_types=1);

namespace Bitrix\Call\Public\Features;

use Bitrix\Main\Config\Feature\AbstractFlag;

/**
 * Automatic quality balancing.
 */
final class AutoBalanceFlag extends AbstractFlag
{
	public function enabledByDefault(): bool
	{
		return Registry::isLegacyOptionEnabledForFlag(self::class);
	}
}
