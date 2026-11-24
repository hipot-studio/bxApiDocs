<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Public\Provider\MainTool;

use Bitrix\Intranet\Internal\Access\MainTool\AccessPolicy;
use Bitrix\Intranet\Internal\Service\MainTool\AvailabilityService;
use Bitrix\Main\Engine\CurrentUser;

class AvailabilityProvider
{
	public function getFirstPage(string $siteId, string $presetId, bool $forAll): string
	{
		$userId = (int)CurrentUser::get()->getId();
		if (
			!AccessPolicy::isFeatureEnabled()
			|| !(new AccessPolicy())->canUsePicker()
		)
		{
			return '';
		}

		return (new AvailabilityService())->getFirstPage($siteId, $userId, $presetId, $forAll);
	}
}
