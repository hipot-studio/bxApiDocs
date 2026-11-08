<?php

declare(strict_types=1);

namespace Bitrix\IntranetMobile\Qualification;

use Bitrix\Main\Result;
use Bitrix\Mobile\Tab\Manager;

interface MobilePresetRuntime
{
	public function isMobileModuleAvailable(): bool;

	public function isManagedModeEnabled(): bool;

	public function createManagedManager(int $userId, string $siteId): Manager;

	public function applyMobileDefault(
		Manager $manager,
		string $siteId,
		int $userId,
		string $presetId,
	): void;

	public function isWebPresetAvailable(string $presetId, string $siteId): bool;

	public function applyWebPreset(string $presetId, string $siteId): Result;
}
