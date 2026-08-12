<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\UseCase;

use Bitrix\Landing\Copilot\Services\CreateAiSiteChecker;
use Bitrix\Landing\Integration\AiAssistant\Dto\SearchLandingSitesDto;
use Bitrix\Landing\Landing;
use Bitrix\Landing\Site;

class SearchLandingSitesHandler extends AbstractSiteHandler
{
	private const SEARCH_SITES_LIMIT = 5;

	public function handle(SearchLandingSitesDto $dto): array
	{
		$searchFilter = [
			'LOGIC' => 'OR',
			'TITLE' => '%' . $dto->query . '%',
		];
		$normalizedCodeQuery = trim($dto->query, " \t\n\r\0\x0B/");
		if ($normalizedCodeQuery !== '')
		{
			$searchFilter['CODE'] = '%' . $normalizedCodeQuery . '%';
		}

		$sites = [];
		$res = Site::getList([
			'select' => ['ID', 'TITLE'],
			'filter' => [
				$searchFilter,
			],
			'order' => ['ID' => 'DESC'],
			'limit' => self::SEARCH_SITES_LIMIT,
			'count_total' => true,
		]);

		while ($row = $res->fetch())
		{
			$siteId = (int)$row['ID'];
			$sites[] = [
				'siteId' => $siteId,
				'siteTitle' => (string)$row['TITLE'],
			];
		}

		$total = (int)$res->getCount();
		$hasMore = $total > self::SEARCH_SITES_LIMIT;
		$pageIdsBySiteId = $this->resolveFirstPageIdsBySiteIds(array_column($sites, 'siteId'));
		$aiSiteChecker = new CreateAiSiteChecker();

		foreach ($sites as $index => $item)
		{
			$siteId = (int)$item['siteId'];
			$pageId = $pageIdsBySiteId[$siteId] ?? 0;
			$isAiSite = $aiSiteChecker->isSiteCreated($siteId);

			$sites[$index]['isAiSite'] = $isAiSite;
			if ($pageId > 0)
			{
				$sites[$index]['pageId'] = $pageId;
			}

			$sites[$index]['siteUrl'] = $isAiSite && $pageId > 0
				? $this->getSitePageEditorUrl($siteId, $pageId)
				: $this->getSitePagesListUrl($siteId)
			;
		}

		return [
			'sites' => $sites,
			'hasMore' => $hasMore,
			'total' => $total,
		];
	}

	private function resolveFirstPageIdsBySiteIds(array $siteIds): array
	{
		$siteIds = array_values(array_unique(array_filter(array_map('intval', $siteIds))));
		if ($siteIds === [])
		{
			return [];
		}

		$pageIdsBySiteId = [];
		$res = Landing::getList([
			'select' => ['ID', 'SITE_ID'],
			'filter' => [
				'SITE_ID' => $siteIds,
				'=FOLDER' => 'N',
			],
			'order' => ['SITE_ID' => 'ASC', 'ID' => 'ASC'],
		]);

		while ($row = $res->fetch())
		{
			$siteId = (int)$row['SITE_ID'];
			if (!isset($pageIdsBySiteId[$siteId]))
			{
				$pageIdsBySiteId[$siteId] = (int)$row['ID'];
			}
		}

		return $pageIdsBySiteId;
	}
}
