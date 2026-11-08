<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\Livefeed;

use Bitrix\Blog\Integration\Socialnetwork\ImportantPostQuery;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Type\DateTime;

final class ImportantPostComponentContext
{
	private bool $isImportantFilterContext;
	private bool $isImportantTemplateContext;
	private bool $isImportantBlockContext;
	private ?ImportantPostQuery $importantPostQuery = null;
	private ?DateTime $importantNow = null;
	private int $importantTimeZoneOffset = 0;
	private ?\DateTimeZone $importantTimeZone = null;

	public function __construct(
		string $templateName,
		private readonly int $userId,
		bool $isAuthorized,
		private readonly array $params,
		array $postUserFields,
		bool $isExtranetSite,
		private readonly string $siteId,
		\CUser $user,
	)
	{
		$this->isImportantFilterContext = (
			isset($this->params['FILTER']['>UF_BLOG_POST_IMPRTNT'])
			|| (int)($this->params['FILTER']['=UF_BLOG_POST_IMPRTNT'] ?? 0) === 1
		);
		$this->isImportantTemplateContext = (
			$templateName === 'important'
			&& $this->userId > 0
			&& $isAuthorized
			&& $this->params['USER_ID'] === $this->userId
			&& $this->isImportantFilterContext
			&& isset($this->params['FILTER']['!POST_PARAM_BLOG_POST_IMPRTNT'])
			&& is_array($this->params['FILTER']['!POST_PARAM_BLOG_POST_IMPRTNT'])
			&& (int)($this->params['FILTER']['!POST_PARAM_BLOG_POST_IMPRTNT']['USER_ID'] ?? 0) === $this->userId
			&& ($this->params['FILTER']['!POST_PARAM_BLOG_POST_IMPRTNT']['VALUE'] ?? null) === 'Y'
		);

		$hasExpectedBlogGroupFilter = empty($this->params['BLOG_GROUP_ID'])
			? !array_key_exists('BLOG_GROUP_ID', $this->params['FILTER'])
			: ($this->params['FILTER']['BLOG_GROUP_ID'] ?? null) === $this->params['BLOG_GROUP_ID'];
		$expectedImportantFilterCount = 9 + (empty($this->params['BLOG_GROUP_ID']) ? 0 : 1);
		$this->isImportantBlockContext = (
			$this->isImportantTemplateContext
			&& $this->params['SOCNET_GROUP_ID'] === 0
			&& empty($this->params['4ME'])
			&& $this->params['CATEGORY_ID'] === 0
			&& $this->params['YEAR'] === 0
			&& $this->params['MONTH'] === 0
			&& $this->params['DAY'] === 0
			&& $this->params['SORT'] === ['DATE_PUBLISH' => 'DESC', 'ID' => 'DESC']
			&& ($this->params['PAGE_SETTINGS']['bDescPageNumbering'] ?? null) === false
			&& (int)($this->params['PAGE_SETTINGS']['nPageSize'] ?? 0) > 0
			&& ($this->params['FILTER']['PUBLISH_STATUS'] ?? null) === 'P'
			&& ($this->params['FILTER']['BLOG_USE_SOCNET'] ?? null) === 'Y'
			&& ($this->params['FILTER']['GROUP_SITE_ID'] ?? null) === $this->siteId
			&& ($this->params['FILTER']['SOCNET_SITE_ID'] ?? null) === (
				$isExtranetSite ? $this->siteId : [$this->siteId, false]
			)
			&& $hasExpectedBlogGroupFilter
			&& (int)($this->params['FILTER']['FOR_USER'] ?? 0) === $this->userId
			&& isset($this->params['FILTER']['<=DATE_PUBLISH'])
			&& isset($postUserFields['UF_IMPRTANT_DATE_END'])
			&& count($this->params['FILTER']) === $expectedImportantFilterCount
			&& class_exists(ImportantPostQuery::class)
		);

		if ($this->isImportantBlockContext)
		{
			$this->importantPostQuery = new ImportantPostQuery();
			$this->importantNow = new DateTime();
			$this->importantTimeZoneOffset = \CTimeZone::GetOffset(null, false, $this->importantNow);
			$this->importantTimeZone = $this->importantNow->getTimeZone();
			if (\CTimeZone::Enabled())
			{
				$importantTimeZoneId = (string)$user->GetParam('TIME_ZONE');
				if (empty($importantTimeZoneId) && \CTimeZone::IsAutoTimeZone($user->GetParam('AUTO_TIME_ZONE')))
				{
					$importantTimeZoneId = \CTimeZone::getTzCookie() ?? '';
				}
				if (empty($importantTimeZoneId))
				{
					$importantTimeZoneId = Option::get('main', 'default_time_zone', '');
				}

				if (!empty($importantTimeZoneId))
				{
					try
					{
						$this->importantTimeZone = new \DateTimeZone($importantTimeZoneId);
					}
					catch (\Throwable)
					{
					}
				}
			}
		}
	}

	public function isImportantFilterContext(): bool
	{
		return $this->isImportantFilterContext;
	}

	public function isImportantTemplateContext(): bool
	{
		return $this->isImportantTemplateContext;
	}

	public function getCacheId(array $cacheId): string
	{
		if ($this->isImportantBlockContext)
		{
			$accessCodes = \CAccess::GetUserCodesArray($this->userId);
			sort($accessCodes, SORT_STRING);
			$cacheId['accessFingerprint'] = md5(serialize($accessCodes));
		}

		return 'blog_blog_' . md5(serialize($cacheId));
	}

	/** @return array{path: string, ttl: int} */
	public function resolveComponentCache(string $cachePath, int $cacheTtl, bool $isManagedCache): array
	{
		if ($this->isImportantTemplateContext && !$isManagedCache)
		{
			$cachePath = ImportantPostCache::getPath($this->userId);
		}
		if ($this->isImportantBlockContext)
		{
			return ImportantPostCache::resolveComponentCache(
				$this->userId,
				$cacheTtl,
				$cachePath,
				$isManagedCache,
			);
		}

		return [
			'path' => $cachePath,
			'ttl' => $cacheTtl,
		];
	}

	public function isCacheValid(array $cacheData): bool
	{
		return (
			!$this->isImportantBlockContext
			|| (
				isset($cacheData['IMPORTANT_CACHE_VALID_UNTIL'])
				&& (int)$cacheData['IMPORTANT_CACHE_VALID_UNTIL'] > $this->importantNow->getTimestamp()
			)
		);
	}

	public function restoreNavigation(array $cacheData, int $navNum): ?\CDBResult
	{
		if (
			!$this->isImportantBlockContext
			|| !isset($cacheData['NAV_SNAPSHOT'])
			|| !is_array($cacheData['NAV_SNAPSHOT'])
		)
		{
			return null;
		}

		$snapshot = $cacheData['NAV_SNAPSHOT'];
		$navResult = new \CDBResult();
		$navResult->bNavStart = true;
		$navResult->bShowAll = false;
		$navResult->NavNum = $navNum;
		$navResult->NavRecordCount = (int)$snapshot['total'];
		$navResult->NavPageNomer = (int)$snapshot['page'];
		$navResult->NavPageCount = (int)$snapshot['pageCount'];
		$navResult->NavPageSize = (int)$snapshot['pageSize'];
		$navResult->NavShowAll = false;
		$navResult->bDescPageNumbering = false;
		$navResult->add_anchor = ($navNum > 1 ? '_' . $navNum : '');
		$GLOBALS['NavNum'] = $navNum;

		return $navResult;
	}

	public function createNavigationSnapshot(\CDBResult $navResult): ?array
	{
		if (!$this->isImportantBlockContext)
		{
			return null;
		}

		return [
			'total' => $navResult->NavRecordCount,
			'page' => $navResult->NavPageNomer,
			'pageCount' => $navResult->NavPageCount,
			'pageSize' => $navResult->NavPageSize,
			'showAll' => false,
		];
	}

	public function getNextPublicationDelay(): ?int
	{
		if ($this->importantPostQuery === null)
		{
			return null;
		}

		return ImportantPostCache::getSharedNextPublicationDelay(
			$this->siteId,
			$this->importantTimeZoneOffset,
			$this->importantNow,
			fn(): ?int => $this->importantPostQuery->getNextPublicationDelay($this->importantNow, $this->siteId),
		);
	}

	public function enrichCacheData(
		array $cacheData,
		?array $navSnapshot,
		int $cacheTtl,
		?int $nextPublicationDelay,
	): array
	{
		if ($navSnapshot !== null)
		{
			$cacheData['NAV_SNAPSHOT'] = $navSnapshot;
		}
		if ($this->isImportantBlockContext)
		{
			$cacheData['IMPORTANT_CACHE_VALID_UNTIL'] = ImportantPostCache::getValidUntilTimestamp(
				$this->importantNow,
				$cacheTtl,
				$nextPublicationDelay,
				$this->importantTimeZone,
			);
		}

		return $cacheData;
	}
}
