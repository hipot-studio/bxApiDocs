<?php

namespace Bitrix\Intranet\Portal;

use Bitrix\Intranet\CurrentUser;
use Bitrix\Intranet\Internals\Trait\Singleton;
use Bitrix\Intranet\Site\FirstPage\FirstPageProvider;
use Bitrix\Main\Data\Cache;
use Bitrix\Main\Web\Uri;

class FirstPage
{
	use Singleton;
	private const FIRST_PAGE_LINK_CACHE = 'FIRST_PAGE_LINK_V2';
	private const FIRST_PAGE_LINK_CACHE_DIR = 'intranet/first_page';
	private Cache $cache;

	/**
	 * @var FirstPage[]|null $pages
	 */
	private ?array $pages;

	protected function __construct()
	{
		$this->cache = Cache::createInstance();
	}

	public function getLink(): string
	{
		return $this->getUriFromCache()->getUri();
	}

	public function clearCache(): void
	{
		$this->cache->clean($this->getCacheUniqueId(), self::FIRST_PAGE_LINK_CACHE_DIR);
	}

	public function clearCacheForAll(): void
	{
		$this->cache->cleanDir(self::FIRST_PAGE_LINK_CACHE_DIR);
	}

	private function getUriFromCache(): Uri
	{
		if (
			$this->cache->initCache(
				86400,
				$this->getCacheUniqueId(),
				self::FIRST_PAGE_LINK_CACHE_DIR,
			)
		)
		{
			$firstPage = $this->cache->getVars();

			if (is_string($firstPage) && !empty($firstPage))
			{
				return new Uri($firstPage);
			}
		}

		$firstPage = $this->getDefaultUri();

		if (preg_match('~^' . SITE_DIR . 'crm~i', $firstPage->getUri()))
		{
			return $firstPage;
		}

		$this->cache->startDataCache();
		$this->cache->endDataCache($firstPage->getUri());

		return $firstPage;
	}

	private function getDefaultUri(): Uri
	{
		return (new FirstPageProvider())->getAvailablePage()->getUri();
	}

	private function getCacheUniqueId(): string
	{
		return self::FIRST_PAGE_LINK_CACHE . '_' . SITE_ID . '_' . CurrentUser::get()->getId();
	}
}
