<?php

declare(strict_types=1);

namespace Bitrix\Market\Internal\Services\AiSearch;

use Bitrix\Market\Internal\Services\AiSearch\Enum\SearchEngine;
use Bitrix\Market\ListTemplates;

class ClassicEngine
{
	public function search(SearchQuery $query): ?array
	{
		try
		{
			$template = new ListTemplates\Search();
			$template->setSearchText($query->getText());
			$template->setFilter(['area' => $query->getArea()]);
			$template->setOrder($query->getOrder());
			$template->setPage($query->getPage());
			$template->setResult(true);

			$result = $template->getInfo();

			return [
				'apps' => $result['APPS'] ?? null,
				'pages' => $result['PAGES'] ?? null,
				'cur_page' => $result['CUR_PAGE'] ?? null,
				'result_count' => $result['RESULT_COUNT'] ?? null,
				'sort_info' => $result['SORT_INFO'] ?? null,
				'engine' => SearchEngine::Classic->value,
				'search_context' => Context::create(SearchEngine::Classic, '', '')->toToken(),
				'query_correction' => null,
			];
		}
		catch (\Throwable)
		{
			return null;
		}
	}
}
