<?php

declare(strict_types=1);

namespace Bitrix\IntranetMobile\Qualification;

use Bitrix\Bitrix24\Preset;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;
use Bitrix\Mobile;
use Bitrix\Mobile\Config\Feature;
use Bitrix\Mobile\Feature\ManagedTabPresetsFeature;
use Bitrix\Mobile\Feature\MobileQualificationPortalDefaultPresetFeature;
use Bitrix\Mobile\Tab;
use Bitrix\Mobile\Tab\Preset\Service\PortalDefaultPresetService;

final class DefaultMobilePresetRuntime implements MobilePresetRuntime
{
	public function isMobileModuleAvailable(): bool
	{
		return Loader::includeModule('mobile');
	}

	public function isManagedModeEnabled(): bool
	{
		return Feature::isEnabled(ManagedTabPresetsFeature::class)
			&& Feature::isEnabled(MobileQualificationPortalDefaultPresetFeature::class);
	}

	public function createManagedManager(int $userId, string $siteId): Tab\Manager
	{
		return Tab\Manager::createManaged($this->createContext($userId, $siteId));
	}

	public function applyMobileDefault(
		Tab\Manager $manager,
		string $siteId,
		int $userId,
		string $presetId,
	): void
	{
		(new PortalDefaultPresetService($manager))->setDefault($siteId, $userId, $presetId);
	}

	public function isWebPresetAvailable(string $presetId, string $siteId): bool
	{
		return Loader::includeModule('bitrix24')
			&& array_key_exists($presetId, Preset\Manager::getAllPresets($siteId));
	}

	public function applyWebPreset(string $presetId, string $siteId): Result
	{
		if (!Loader::includeModule('bitrix24'))
		{
			return (new Result())->addError(new Error('Module "bitrix24" is not installed.'));
		}

		return Preset\Manager::applyPreset($presetId, $siteId, 'qualification');
	}

	private function createContext(int $userId, string $siteId): Mobile\Context
	{
		return new Mobile\Context([
			'userId' => $userId,
			'siteId' => $siteId,
			'siteDir' => defined('SITE_DIR') ? SITE_DIR : '/',
		]);
	}
}
