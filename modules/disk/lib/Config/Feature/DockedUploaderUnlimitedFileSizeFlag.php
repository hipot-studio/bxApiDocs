<?php

namespace Bitrix\Disk\Config\Feature;

use Bitrix\Main\Config\Feature\AbstractFlag;

final class DockedUploaderUnlimitedFileSizeFlag extends AbstractFlag
{
	public function enabledByDefault(): bool
	{
		return false;
	}
}
