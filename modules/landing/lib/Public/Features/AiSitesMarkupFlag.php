<?php

declare(strict_types=1);

namespace Bitrix\Landing\Public\Features;

use Bitrix\Main\Config\Feature\AbstractFlag;

/**
 * Portal-wide switch of the AI sites generation: enabled selects markup, disabled selects legacy.
 *
 * The class name is the stored code of the flag, so the class must not be renamed or moved.
 */
final class AiSitesMarkupFlag extends AbstractFlag
{
	public function enabledByDefault(): bool
	{
		return false;
	}
}
