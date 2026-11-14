<?php

namespace Bitrix\Disk\Config\Feature;

use Bitrix\Main\Config\Feature\AbstractFlag;

final class DockedUploaderFlag extends AbstractFlag
{
	public function enabledByDefault(): bool
	{
		return false;
	}
}
