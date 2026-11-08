<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Public\Features;

use Bitrix\Main\Config\Feature\AbstractFlag;

final class ManagedTabPresetsFeature extends AbstractFlag
{
	public function enabledByDefault(): bool
	{
		return true;
	}
}
