<?php

declare(strict_types=1);

namespace Bitrix\Call\Public\Features;

use Bitrix\Main\Config\Feature\AbstractFlag;

/**
 * Disabling the camera of users who join a running call.
 */
final class DisableCameraForNewJoinersFlag extends AbstractFlag
{
	public function enabledByDefault(): bool
	{
		return Registry::isLegacyOptionEnabledForFlag(self::class);
	}
}
