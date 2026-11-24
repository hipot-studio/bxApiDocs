<?php

declare(strict_types=1);

namespace Bitrix\Disk\Config\Feature;

use Bitrix\Main\Config\Feature\AbstractFlag;

final class VibeofficeSharedEditingFlag extends AbstractFlag
{
	public function enabledByDefault(): bool
	{
		return false;
	}
}
