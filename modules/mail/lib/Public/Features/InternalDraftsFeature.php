<?php

declare(strict_types=1);

namespace Bitrix\Mail\Public\Features;

use Bitrix\Main\Config\Feature\AbstractFlag;

final class InternalDraftsFeature extends AbstractFlag
{
	public function enabledByDefault(): bool
	{
		return true;
	}
}
