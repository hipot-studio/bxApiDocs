<?php

namespace Bitrix\Mobile\Menu\Service;

use Bitrix\Main\Data\Cache;
use Bitrix\Main\Data\TaggedCache;
use Bitrix\Main\Config\Feature;
use Bitrix\Mobile\Public\Features\ManagedTabPresetsFeature;
use Bitrix\Mobile\Tab\Preset\Service\PortalDefaultPresetCacheVersion;
use Bitrix\MobileApp\Mobile;

class MenuListCache implements MenuListCacheInterface
{
	private const CACHE_DIR_PREFIX = '/mobile/menu/user_';
	private const CACHE_KEY_PREFIX = 'mobile_menu_';
	private int $userId;
	private int $defaultTtl;
	private int $apiVersion;
	private string $portalDefaultPresetVersion;

	public function __construct(
		int $userId,
		?PortalDefaultPresetCacheVersion $portalDefaultPresetCacheVersion = null,
		?string $siteId = null,
		?bool $managedTabPresetsEnabled = null,
	)
	{
		$this->userId = $userId;
		$this->defaultTtl = defined("BX_COMP_MANAGED_CACHE") ? 2592000 : 600;
		$this->apiVersion = Mobile::getInstance()::getApiVersion();
		$managedTabPresetsEnabled ??= Feature::isEnabled(ManagedTabPresetsFeature::class);
		$this->portalDefaultPresetVersion = $managedTabPresetsEnabled
			? 'managed:on:' . ($portalDefaultPresetCacheVersion ?? new PortalDefaultPresetCacheVersion())
				->get($siteId ?? $this->getSiteId())
			: 'managed:off';
	}

	public function get(): ?array
	{
		$cache = Cache::createInstance();

		$cacheKey = $this->generateCacheKey();
		if ($cache->initCache($this->defaultTtl, $cacheKey, $this->getCacheDir()))
		{
			return $cache->getVars();
		}

		return null;
	}

	public function set(array $data): void
	{
		$cache = Cache::createInstance();
		$taggedCache = \Bitrix\Main\Application::getInstance()->getTaggedCache();
		$cacheKey = $this->generateCacheKey();
		$cacheDir = $this->getCacheDir();

		$taggedCache->startTagCache($cacheDir);
		$this->registerTags($taggedCache);
		$taggedCache->endTagCache();

		if ($cache->startDataCache($this->defaultTtl, $cacheKey, $cacheDir))
		{
			$cache->endDataCache($data);
		}
	}

	public function clear(): void
	{
		$cache = Cache::createInstance();
		$cacheKey = $this->generateCacheKey();
		$cache->clean($cacheKey, $this->getCacheDir());
	}

	private function getCacheDir(): string
	{
		return self::CACHE_DIR_PREFIX . $this->userId . '/';
	}

	private function generateCacheKey(): string
	{
		$keyParts = [
			$this->userId,
			IsModuleInstalled('extranet') ? 'extranet' : 'intranet',
			LANGUAGE_ID,
			$this->apiVersion,
			$this->portalDefaultPresetVersion,
		];

		return self::CACHE_KEY_PREFIX . md5(implode('_', $keyParts));
	}

	private function getSiteId(): string
	{
		return defined('SITE_ID') ? (string)SITE_ID : '';
	}

	private function registerTags(TaggedCache $taggedCache): void
	{
		$taggedCache->registerTag('sonet_group');
		$taggedCache->registerTag('crm_initiated');
		$taggedCache->registerTag('USER_CARD_' . (int)($this->userId / TAGGED_user_card_size));
		$taggedCache->registerTag('sonet_user2group_U' . $this->userId);
		$taggedCache->registerTag('mobile_custom_menu' . $this->userId);
		$taggedCache->registerTag('crm_change_role');
		$taggedCache->registerTag('bitrix24_left_menu');
	}
}
