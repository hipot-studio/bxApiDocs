<?php

namespace Bitrix\Crm\Service\Timeline\Navigation;

use Bitrix\Crm\Public\Features\TimelineNavigation;
use Bitrix\Main\Config\Feature;

final class Availability
{
	public function isAvailable(int $entityTypeId, int $entityId): bool
	{
		return Feature::isEnabled(TimelineNavigation::class);
	}
}
