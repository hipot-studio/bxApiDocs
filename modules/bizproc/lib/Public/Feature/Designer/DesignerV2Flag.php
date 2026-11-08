<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Feature\Designer;

use Bitrix\Main\Config\Feature\AbstractFlag;

final class DesignerV2Flag extends AbstractFlag
{
	public function enabledByDefault(): bool
	{
		return true;
	}
}
