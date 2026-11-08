<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\Timeman;

use Bitrix\Intranet\Settings\Tools\ToolsManager;
use Bitrix\Main\Loader;
use CBXFeatures;
use CTimeMan;

class WorkTime
{
	public static function canUse(): bool
	{
		$isExtranetSite = Loader::includeModule('extranet') && \CExtranet::IsExtranetSite();

		return !$isExtranetSite
			&& CBXFeatures::IsFeatureEnabled('timeman')
			&& Loader::includeModule('timeman')
			&& CTimeMan::canUse()
			&& ToolsManager::getInstance()->checkAvailabilityByToolId('worktime');
	}
}
