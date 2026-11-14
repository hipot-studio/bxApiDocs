<?php

declare(strict_types=1);

namespace Bitrix\Call\Public\Features;

use Bitrix\Main\Config\Feature\AbstractFlag;

/**
 * Manual view toggling in the mobile call interface.
 */
final class ViewToggleFlag extends AbstractFlag
{
	public function enabledByDefault(): bool
	{
		return Registry::isLegacyOptionEnabledForFlag(self::class);
	}
}
