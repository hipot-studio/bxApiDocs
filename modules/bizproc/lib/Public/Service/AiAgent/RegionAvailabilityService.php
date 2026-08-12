<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Service\AiAgent;

use Bitrix\Main\Application;

class RegionAvailabilityService implements RegionAvailabilityServiceInterface
{
	private const RESTRICTED_REGIONS = ['cn'];

	private static ?bool $isAvailable = null;

	public function isAvailable(): bool
	{
		if (self::$isAvailable === null)
		{
			self::$isAvailable = !in_array($this->getPortalRegion(), self::RESTRICTED_REGIONS, true);
		}

		return self::$isAvailable;
	}

	protected function getPortalRegion(): ?string
	{
		return Application::getInstance()->getLicense()->getRegion();
	}
}
