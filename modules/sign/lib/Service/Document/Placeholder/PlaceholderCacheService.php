<?php

namespace Bitrix\Sign\Service\Document\Placeholder;

use Bitrix\Sign\Config;
use Bitrix\Sign\Util\MainCache;

class PlaceholderCacheService
{
	/** Signature of a list built without a scenario context, so without scenario filtering. */
	public const NO_SCENARIO_SIGNATURE = 'nocontext';

	private const LEGACY_PLACEHOLDER_LIST_CACHE_KEY = 'sign_document_placeholder_data';
	private const VERIFIED_PLACEHOLDER_LIST_CACHE_KEY = 'sign_document_placeholder_data_verified';
	private const RAW_PLACEHOLDERS_CACHE_KEY_PREFIX = 'sign_document_raw_placeholders_';
	private const RAW_PLACEHOLDERS_CACHE_TTL = 10 * 365 * 24 * 60 * 60;

	// own scope of the cache of the module: the lists are dropped as a whole, see the invalidation
	private const CACHE_SCOPE = 'placeholder';

	/**
	 * The raw codes of a document body have a scope of their own, because they answer a question the
	 * lists do not: what the file holds, not what the portal offers. Sharing the scope of the lists
	 * would let a single added user field drop the warmed codes of every document of the portal.
	 *
	 * Nothing drops this scope as a whole, and that is deliberate: an entry describes one body of one
	 * document, so no portal event makes a mass reset right - dropping the scope would only throw away
	 * the warmup of every other document. The document service deletes a key when that exact body is
	 * successfully replaced or its document is removed.
	 */
	private const RAW_CACHE_SCOPE = 'placeholder_raw';

	private readonly MainCache $cache;
	private readonly MainCache $rawCache;
	private readonly MainCache $rootCache;
	private readonly Config\Storage $config;

	/**
	 * Typed by the implementation and not by Contract\Util\Cache, because the contract carries no
	 * scope: this service owns one and drops it as a whole.
	 */
	public function __construct(
		MainCache $cache,
		?Config\Storage $config = null,
	)
	{
		$this->rootCache = $cache;
		$this->cache = $cache->withScope(self::CACHE_SCOPE);
		$this->rawCache = $cache->withScope(self::RAW_CACHE_SCOPE)->setTtl(self::RAW_PLACEHOLDERS_CACHE_TTL);
		$this->config = $config ?? Config\Storage::instance();
	}

	public function getPlaceholderList(string $signature): mixed
	{
		return $this->cache->get($this->getVersionedPlaceholderListCacheKey($signature));
	}

	public function setPlaceholderList(string $signature, array $placeholderList): MainCache
	{
		return $this->cache->set($this->getVersionedPlaceholderListCacheKey($signature), $placeholderList);
	}

	public function getPlaceholderListByHcmLinkCompanyId(
		int $hcmLinkCompanyId,
		string $signature = self::NO_SCENARIO_SIGNATURE,
	): mixed
	{
		return $this->cache->get($this->getPlaceholderListCacheKeyByHcmLinkCompanyId($hcmLinkCompanyId, $signature));
	}

	public function setPlaceholderListByHcmLinkCompanyId(
		int $hcmLinkCompanyId,
		array $placeholderList,
		string $signature = self::NO_SCENARIO_SIGNATURE,
	): MainCache
	{
		return $this->cache->set(
			$this->getPlaceholderListCacheKeyByHcmLinkCompanyId($hcmLinkCompanyId, $signature),
			$placeholderList,
		);
	}

	/**
	 * Raw placeholder codes of the document body, as they came from the safe service. The key carries
	 * neither the scenario nor the epoch of the list, but it does carry the blank: replacing the body
	 * of a document - through the online editor or through a blank change - always moves the document
	 * to a new blank, so the entry of the previous body becomes unreachable instead of answering for
	 * a file it no longer describes. What is left of it expires by the own TTL of MainCache.
	 *
	 * @return string[]|null null when nothing was precomputed for this body yet
	 */
	public function getRawPlaceholders(int $documentId, int $blankId): ?array
	{
		$codes = $this->rawCache->get($this->getRawPlaceholdersCacheKey($documentId, $blankId));

		return is_array($codes) ? $codes : null;
	}

	/**
	 * @param string[] $codes
	 */
	public function setRawPlaceholders(int $documentId, int $blankId, array $codes): MainCache
	{
		return $this->rawCache->set($this->getRawPlaceholdersCacheKey($documentId, $blankId), $codes);
	}

	public function deleteRawPlaceholders(int $documentId, int $blankId): MainCache
	{
		return $this->rawCache->delete($this->getRawPlaceholdersCacheKey($documentId, $blankId));
	}

	/**
	 * Drops every cached fields list at once. Two resets meet here, and both are needed: the scope of
	 * the lists is dropped as a whole, which is the only one that reaches the per-company lists of the
	 * external HR system (their keys hold company ids and cannot be enumerated), while the epoch of the
	 * main key is bumped, which makes every scenario variant unreachable at once - a file cache deletes
	 * a directory lazily, so an entry can still be readable when the next request arrives. The drop
	 * stays inside the own scope of the lists: the keys of the other consumers of the cache of the
	 * module survive it, the raw codes of the document bodies among them.
	 */
	public function invalidateDocumentPlaceholderListCache(): void
	{
		$this->cache->deleteAll();
		$this->deleteListsOfTheRootScope();
		$this->config->bumpPlaceholderListCacheEpoch();
	}

	/**
	 * Lists the versions before the move wrote into the root of the cache of the module. Their
	 * per-company keys hold company ids, cannot be enumerated and wait for their ttl.
	 */
	private function deleteListsOfTheRootScope(): void
	{
		$this->rootCache->delete(self::LEGACY_PLACEHOLDER_LIST_CACHE_KEY);
		$this->rootCache->delete(self::VERIFIED_PLACEHOLDER_LIST_CACHE_KEY);
	}

	private function getVersionedPlaceholderListCacheKey(string $signature): string
	{
		$epoch = $this->config->getPlaceholderListCacheEpoch();

		return $this->getPlaceholderListCacheKey() . '_v' . $epoch . '_' . $signature;
	}

	private function getPlaceholderListCacheKey(): string
	{
		return $this->config->isPlaceholderVerifiedAliasFilterDisabled()
			? self::LEGACY_PLACEHOLDER_LIST_CACHE_KEY
			: self::VERIFIED_PLACEHOLDER_LIST_CACHE_KEY;
	}

	private function getPlaceholderListCacheKeyByHcmLinkCompanyId(int $hcmLinkCompanyId, string $signature): string
	{
		return $this->getPlaceholderListCacheKey() . "_hcm_link_company_{$hcmLinkCompanyId}_{$signature}";
	}

	private function getRawPlaceholdersCacheKey(int $documentId, int $blankId): string
	{
		return self::RAW_PLACEHOLDERS_CACHE_KEY_PREFIX . $documentId . '_' . $blankId;
	}
}
