<?php

namespace Bitrix\Sign\Service\Document\Placeholder;

use Bitrix\Sign\Config;
use Bitrix\Sign\Util\MainCache;

class PlaceholderCacheService
{
	private const LEGACY_PLACEHOLDER_LIST_CACHE_KEY = 'sign_document_placeholder_data';
	private const VERIFIED_PLACEHOLDER_LIST_CACHE_KEY = 'sign_document_placeholder_data_verified';

	private readonly Config\Storage $config;

	public function __construct(
		private readonly MainCache $cache,
		?Config\Storage $config = null,
	)
	{
		$this->config = $config ?? Config\Storage::instance();
	}

	public function getPlaceholderList(): mixed
	{
		return $this->cache->get($this->getPlaceholderListCacheKey());
	}

	public function setPlaceholderList(array $placeholderList): MainCache
	{
		return $this->cache->set($this->getPlaceholderListCacheKey(), $placeholderList);
	}

	public function getPlaceholderListByHcmLinkCompanyId(int $hcmLinkCompanyId): mixed
	{
		return $this->cache->get($this->getPlaceholderListCacheKeyByHcmLinkCompanyId($hcmLinkCompanyId));
	}

	public function setPlaceholderListByHcmLinkCompanyId(int $hcmLinkCompanyId, array $placeholderList): MainCache
	{
		return $this->cache->set($this->getPlaceholderListCacheKeyByHcmLinkCompanyId($hcmLinkCompanyId), $placeholderList);
	}

	public function invalidateDocumentPlaceholderListCache(): void
	{
		$this->cache->delete(self::LEGACY_PLACEHOLDER_LIST_CACHE_KEY);
		$this->cache->delete(self::VERIFIED_PLACEHOLDER_LIST_CACHE_KEY);
	}

	private function getPlaceholderListCacheKey(): string
	{
		return $this->config->isPlaceholderVerifiedAliasFilterDisabled()
			? self::LEGACY_PLACEHOLDER_LIST_CACHE_KEY
			: self::VERIFIED_PLACEHOLDER_LIST_CACHE_KEY;
	}

	private function getPlaceholderListCacheKeyByHcmLinkCompanyId(int $hcmLinkCompanyId): string
	{
		return $this->getPlaceholderListCacheKey() . "_hcm_link_company_{$hcmLinkCompanyId}";
	}
}
