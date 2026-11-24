<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Config\Feature;

use Bitrix\Main\Config\Feature\AbstractFlag;

final class AnnualSummaryAdminFlag extends AbstractFlag
{
	public function enabledByDefault(): bool
	{
		return false;
	}
}
