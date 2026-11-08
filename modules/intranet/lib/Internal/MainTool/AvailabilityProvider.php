<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\MainTool;

use Bitrix\Call\Settings;
use Bitrix\Crm\Settings\EntityViewSettings;
use Bitrix\Intranet\Settings\Tools;
use Bitrix\Intranet\UI\LeftMenu\Preset\Social;
use Bitrix\Landing\Rights;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Loader;
use Bitrix\Main\ModuleManager;
use Bitrix\Main\SiteTable;

class AvailabilityProvider
{
	/** @return list<array{id:string, canApplyForAll:bool}> */
	public function getAvailablePresets(string $siteId, int $userId): array
	{
		if (!AccessPolicy::isFeatureEnabled() || $userId <= 0 || (int)CurrentUser::get()->getId() !== $userId)
		{
			return [];
		}

		$presets = [];
		$availability = [
			'crm' => $this->isCrmAvailable(),
			'tasks' => $this->isTasksAvailable($userId),
			'social' => $this->getSocialFirstPage($siteId, false) !== null,
			'sites' => $this->isSitesAvailable(),
			'sync' => $this->isSyncAvailable(),
		];
		foreach ($availability as $id => $available)
		{
			if ($available)
			{
				$presets[] = [
					'id' => $id,
					'canApplyForAll' => $id !== 'sync' || $this->isSyncAvailableForAll(),
				];
			}
		}

		return $presets;
	}

	public function getFirstPage(string $siteId, int $userId, string $presetId, bool $forAll): string
	{
		$available = false;
		foreach ($this->getAvailablePresets($siteId, $userId) as $preset)
		{
			if ($preset['id'] === $presetId && (!$forAll || $preset['canApplyForAll']))
			{
				$available = true;
				break;
			}
		}
		if (!$available)
		{
			return '';
		}

		$siteDir = $this->getSiteDirectory($siteId);
		return match ($presetId)
		{
			'crm' => EntityViewSettings::getDefaultPageUrl(),
			'tasks' => $siteDir . 'company/personal/user/' . ($forAll ? '#USER_ID#' : $userId) . '/tasks/',
			'social' => $this->getSocialFirstPage($siteId, $forAll) ?? '',
			'sites' => $siteDir . 'sites/',
			'sync' => $siteDir . 'sync/',
			default => '',
		};
	}

	protected function isCrmAvailable(): bool
	{
		return ModuleManager::isModuleInstalled('crm')
			&& Loader::includeModule('crm')
			&& (new Tools\Crm())->isEnabled()
			&& \CCrmPerms::IsAccessEnabled()
		;
	}

	protected function isTasksAvailable(int $userId): bool
	{
		if (
			!ModuleManager::isModuleInstalled('tasks')
			|| !(new Tools\Tasks())->isEnabled()
			|| !Loader::includeModule('socialnetwork')
		)
		{
			return false;
		}

		$activeFeatures = \CSocNetFeatures::GetActiveFeatures(SONET_ENTITY_USER, $userId);
		$allowedFeatures = \CSocNetAllowed::GetAllowedFeatures();

		return isset($allowedFeatures['tasks']['allowed'])
			&& in_array(SONET_ENTITY_USER, $allowedFeatures['tasks']['allowed'], true)
			&& is_array($activeFeatures)
			&& in_array('tasks', $activeFeatures, true)
		;
	}

	protected function isSitesAvailable(): bool
	{
		if (
			!ModuleManager::isModuleInstalled('landing')
			|| !Loader::includeModule('landing')
			|| !(new Tools\Sites())->isEnabled()
			|| !Rights::hasAdditionalRight(Rights::ADDITIONAL_RIGHTS['menu24'])
		)
		{
			return false;
		}

		return !Loader::includeModule('bitrix24')
			|| in_array(\CBitrix24::getPortalZone(), ['ru', 'kz', 'by', 'uz'], true)
			|| \Bitrix\Bitrix24\Release::isAvailable('landing')
		;
	}

	protected function isSyncAvailable(): bool
	{
		return ModuleManager::isModuleInstalled('call')
			&& Loader::includeModule('call')
			&& Settings::isSyncPresetEnabled()
		;
	}

	protected function isSyncAvailableForAll(): bool
	{
		if (!ModuleManager::isModuleInstalled('call') || !Loader::includeModule('call'))
		{
			return false;
		}

		// Older call versions have no portal-level flag yet and keep the setting in the module option.
		if (!method_exists(Settings::class, 'isSyncPresetEnabledForPortal'))
		{
			return (bool)Option::get('call', 'call_sync_preset_enabled', false, '');
		}

		return Settings::isSyncPresetEnabledForPortal();
	}

	protected function getSocialFirstPage(string $siteId, bool $forAll): ?string
	{
		$structure = (new Social($siteId))->getStructure();
		$provider = new TeamWorkRouteProvider($structure['shown']['menu_teamwork'] ?? []);

		return $provider->getRoute($forAll);
	}

	protected function getSiteDirectory(string $siteId): string
	{
		$site = SiteTable::query()->setSelect(['DIR'])->where('LID', $siteId)->fetch();

		return $site ? (string)$site['DIR'] : '/';
	}
}
