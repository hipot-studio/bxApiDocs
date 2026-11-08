<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\MainTool;

use Bitrix\Main\Config\Feature\AbstractFlag;

class MainToolPickerRedesignFlag extends AbstractFlag
{
	public function enabledByDefault(): bool
	{
		return false;
	}
}
