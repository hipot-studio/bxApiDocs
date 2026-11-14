<?php

declare(strict_types=1);

namespace Bitrix\Market\Internal\Services\AiSearch;

use Bitrix\Market\Internal\Services\AiSearch\Enum\SearchEngine;

final class ResponseMapper
{
	public function map(mixed $response): ?array
	{
		if (!is_array($response) || array_key_exists('ERROR', $response) || !isset($response['ITEMS']) || !is_array($response['ITEMS']))
		{
			return null;
		}

		foreach ($response['ITEMS'] as $item)
		{
			if (!is_array($item))
			{
				return null;
			}
		}

		$engine = ($response['ENGINE'] ?? null) === SearchEngine::Ai->value ? SearchEngine::Ai : SearchEngine::ClassicFallback;

		return [
			'apps' => array_values($response['ITEMS']),
			'pages' => (int)($response['PAGES'] ?? 0),
			'cur_page' => (int)($response['CUR_PAGE'] ?? 0),
			'result_count' => (int)($response['RESULT_COUNT'] ?? 0),
			'sort_info' => null,
			'engine' => $engine->value,
			'search_context' => Context::create(
				$engine,
				$this->scalarToString($response['SEARCH_REQUEST_ID'] ?? null),
				$this->scalarToString($response['RESULT_SET_ID'] ?? null),
			)->toToken(),
			'query_correction' => $this->mapQueryCorrection($response['QUERY_CORRECTION'] ?? null),
		];
	}

	private function scalarToString(mixed $value): string
	{
		return is_scalar($value) ? (string)$value : '';
	}

	private function mapQueryCorrection(mixed $queryCorrection): ?array
	{
		if (!is_array($queryCorrection) || ($queryCorrection['applied'] ?? null) !== true)
		{
			return null;
		}

		return [
			'applied' => true,
			'corrected_query' => (string)($queryCorrection['corrected_query'] ?? ''),
			'original_query' => $this->findOriginalQuery($queryCorrection['actions'] ?? null),
		];
	}

	private function findOriginalQuery(mixed $actions): string
	{
		foreach (is_array($actions) ? $actions : [] as $action)
		{
			if (is_array($action) && ($action['type'] ?? null) === 'search_original')
			{
				return is_scalar($action['query'] ?? null) ? (string)$action['query'] : '';
			}
		}

		return '';
	}
}
