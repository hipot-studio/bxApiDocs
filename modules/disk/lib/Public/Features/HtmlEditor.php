<?php

namespace Bitrix\Disk\Public\Features;

use Bitrix\Main\Config\Feature\AbstractFlag;

final class HtmlEditor extends AbstractFlag
{
	public function enabledByDefault(): bool
	{
		return false;
	}
}
