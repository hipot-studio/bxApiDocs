<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Service\AiAgent;

interface RegionAvailabilityServiceInterface
{
	/**
	 * Returns true if the AI agents section is available in the portal region.
	 *
	 * @return bool
	 */
	public function isAvailable(): bool;
}
