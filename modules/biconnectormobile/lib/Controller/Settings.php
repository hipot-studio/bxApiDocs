<?php

declare(strict_types=1);

namespace Bitrix\BIConnectorMobile\Controller;

use Bitrix\BIConnectorMobile\Public\Feature\UnifiedFiltersFlag;
use Bitrix\Main\Config\Feature;

class Settings extends Base
{
	protected function getQueryActionNames(): array
	{
		return ['isUnifiedFiltersEnabled'];
	}

	public function isUnifiedFiltersEnabledAction(): bool
	{
		return Feature::isEnabled(UnifiedFiltersFlag::class);
	}
}
