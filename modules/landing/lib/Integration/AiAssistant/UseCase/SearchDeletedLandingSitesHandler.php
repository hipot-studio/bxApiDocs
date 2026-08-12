<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\UseCase;

use Bitrix\Landing\Integration\AiAssistant\Dto\SearchDeletedLandingSitesDto;
use Bitrix\Landing\Site;
use InvalidArgumentException;

class SearchDeletedLandingSitesHandler extends AbstractSiteHandler
{
	private const SEARCH_SITES_LIMIT = 5;
	private const SEARCH_SITES_FETCH_LIMIT = self::SEARCH_SITES_LIMIT + 1;

	public function handle(SearchDeletedLandingSitesDto $dto): array
	{
		if ($this->isWildcardQuery($dto->query))
		{
			throw new InvalidArgumentException('Search query must contain a specific site title or site code.');
		}

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
		$hasMore = false;
		$res = Site::getList([
			'select' => ['ID', 'TITLE'],
			'filter' => [
				$searchFilter,
				'=DELETED' => 'Y',
			],
			'order' => ['ID' => 'DESC'],
			'limit' => self::SEARCH_SITES_FETCH_LIMIT,
		]);

		while ($row = $res->fetch())
		{
			if (count($sites) >= self::SEARCH_SITES_LIMIT)
			{
				$hasMore = true;

				break;
			}

			$sites[] = [
				'siteId' => (int)$row['ID'],
				'siteTitle' => (string)$row['TITLE'],
			];
		}

		return [
			'sites' => $sites,
			'hasMore' => $hasMore,
		];
	}

	private function isWildcardQuery(?string $query): bool
	{
		if ($query === null)
		{
			return true;
		}

		return trim($query, " \t\n\r\0\x0B%_*/") === '';
	}
}
