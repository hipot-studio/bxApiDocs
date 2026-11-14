<?php

declare(strict_types=1);

namespace Bitrix\Call\Public\Features;

use Bitrix\Main\Config\Feature\AbstractFlag;

/**
 * Connection quality (QoS) indicator in the mobile app. Works only on top of the global QoS
 * feature: the facade checks that, since the global one has a personal level a requirement cannot see.
 */
final class MobileQosFlag extends AbstractFlag
{
	public function enabledByDefault(): bool
	{
		return Registry::isLegacyOptionEnabledForFlag(self::class);
	}
}
