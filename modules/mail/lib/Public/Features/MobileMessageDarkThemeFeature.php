<?php

declare(strict_types=1);

namespace Bitrix\Mail\Public\Features;

use Bitrix\Main\Config\Feature\AbstractFlag;

/**
 * Repaint of message bodies for the dark theme of the mobile clients. The recolor works inside the mail
 * module alone, so the flag carries no requirements and only keeps the switch that turns it off.
 */
final class MobileMessageDarkThemeFeature extends AbstractFlag
{
	public function enabledByDefault(): bool
	{
		return true;
	}
}
