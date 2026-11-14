<?php

namespace Bitrix\Market\Controller;

use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Engine\Response\AjaxJson;
use Bitrix\Market\Internal\Services\AiSearch;

class Search extends Controller
{
	private array $result = [];

	public function getAppsAction(string $text, int $page, string $area, array $order = []): AjaxJson
	{
		$template = new \Bitrix\Market\ListTemplates\Search();
		$template->setSearchText($text);
		$template->setFilter(['area' => $area]);
		$template->setOrder($order);
		$template->setPage($page);
		$template->setResult(true);
		$this->result = $template->getInfo();
		if (is_array($this->result['APPS'] ?? null))
		{
			$this->result['APPS'] = (new AiSearch\AppsDecorator())->decorate($this->result['APPS']);
		}

		return AjaxJson::createSuccess([
			'apps' => $this->result['APPS'],
			'pages' => $this->result['PAGES'],
			'cur_page' => $this->result['CUR_PAGE'],
			'result_count' => $this->result['RESULT_COUNT'],
			'sort_info' => $this->result['SORT_INFO'],
		]);
	}
}
