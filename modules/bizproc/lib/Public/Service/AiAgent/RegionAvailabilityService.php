<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Service\AiAgent;

use Bitrix\Main\Application;

class RegionAvailabilityService implements RegionAvailabilityServiceInterface
{
	private const RESTRICTED_REGIONS = ['cn'];

	public function isAvailable(): bool
	{
		return !in_array($this->getPortalRegion(), self::RESTRICTED_REGIONS, true);
	}

	protected function getPortalRegion(): ?string
	{
		return Application::getInstance()->getLicense()->getRegion();
	}
}
