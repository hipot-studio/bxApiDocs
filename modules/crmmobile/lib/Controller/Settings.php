<?php

declare(strict_types=1);

namespace Bitrix\CrmMobile\Controller;

use Bitrix\CrmMobile\Public\Feature\UnifiedFiltersFlag;
use Bitrix\Main\Config\Feature;
use Bitrix\Main\Engine\ActionFilter\CloseSession;

class Settings extends Base
{
	public function configureActions(): array
	{
		return [
			'isUnifiedFiltersEnabled' => [
				'+prefilters' => [new CloseSession()],
			],
		];
	}

	/**
	 * @ajaxAction crmmobile.Settings.isUnifiedFiltersEnabled
	 */
	public function isUnifiedFiltersEnabledAction(): bool
	{
		return Feature::isEnabled(UnifiedFiltersFlag::class);
	}
}
