<?php

namespace Bitrix\Sign\Service\Document\Placeholder;

use Bitrix\Sign\Util\MainCache;

class PlaceholderCacheService
{
	private const  PLACEHOLDER_LIST_CACHE_KEY = 'sign_document_placeholder_data';

	public function __construct(
		private readonly MainCache $cache,
	)
	{
	}

	public function getPlaceholderList(): mixed
	{
		return $this->cache->get(self::PLACEHOLDER_LIST_CACHE_KEY);
	}

	public function setPlaceholderList(array $placeholderList): MainCache
	{
		return $this->cache->set(self::PLACEHOLDER_LIST_CACHE_KEY, $placeholderList);
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
		$this->cache->delete(self::PLACEHOLDER_LIST_CACHE_KEY);
	}

	private function getPlaceholderListCacheKeyByHcmLinkCompanyId(int $hcmLinkCompanyId): string
	{
		return "sign_document_placeholder_data_hcm_link_company_{$hcmLinkCompanyId}";
	}
}