<?php

declare(strict_types=1);

namespace Bitrix\Market\Internal\Services\AiSearch;

use Bitrix\Market\Rest\Actions;
use Bitrix\Market\Rest\Transport;

final class AiEngine
{
	public const AI_SOCKET_TIMEOUT = 3;
	public const AI_STREAM_TIMEOUT = 8;

	private const PAGE_SIZE = 20;
	private const MIN_QUERY_LENGTH = 2;

	private const AREA_FILTERS = [
		'apps' => ['product_types' => ['application']],
		'sites' => ['product_types' => ['site']],
	];

	public function __construct(
		private readonly ResponseMapper $mapper,
	)
	{
	}

	public function supports(SearchQuery $query): bool
	{
		if (mb_strlen($query->getText()) < self::MIN_QUERY_LENGTH)
		{
			return false;
		}

		$area = $query->getArea();

		return $area === '' || $area === 'all' || isset(self::AREA_FILTERS[$area]);
	}

	public function search(SearchQuery $query, Visitor $visitor): ?array
	{
		try
		{
			$response = Transport::instance()->batch(
				[Actions::METHOD_AI_SEARCH_ITEMS => [Actions::METHOD_AI_SEARCH_ITEMS, $this->buildPayload($query, $visitor)]],
				self::AI_SOCKET_TIMEOUT,
				self::AI_STREAM_TIMEOUT,
			);

			return $this->mapper->map($response[Actions::METHOD_AI_SEARCH_ITEMS] ?? null);
		}
		catch (\Throwable)
		{
			return null;
		}
	}

	private function buildPayload(SearchQuery $query, Visitor $visitor): array
	{
		$payload = [
			'q' => $query->getText(),
			'page' => $query->getPage(),
			'onPageSize' => self::PAGE_SIZE,
			'visitor_id' => $visitor->getVisitorId(),
			'search_session_id' => $visitor->getSearchSessionId(),
			'correction_mode' => $query->getCorrectionMode()->value,
		];

		$area = $query->getArea();
		if (isset(self::AREA_FILTERS[$area]))
		{
			$payload['filters'] = self::AREA_FILTERS[$area];
		}

		return $payload;
	}
}
