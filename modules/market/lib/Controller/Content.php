<?php

namespace Bitrix\Market\Controller;

use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Engine\Response\AjaxJson;
use Bitrix\Main\Web\Uri;
use Bitrix\Market\Internal\Services\Mobile\PageResolver as MobilePageResolver;
use Bitrix\Market\PageRules;

class Content extends Controller
{
	public function loadAction(string $page): AjaxJson
	{
		$path = $page;

		$uri = new Uri($page);
		if (!empty($uri->getPath())) {
			$path = $uri->getPath();
		}
		if (mb_substr($path, -1, 1) == "/") {
			$path .= "index.php";
		}

		$pageRules = $this->createPageRules($path, $this->getQueryParams($uri->getQuery()));
		$data = $pageRules->getComponentData();

		return AjaxJson::createSuccess([
			'params' => is_array($data['params'] ?? null) ? $data['params'] : [],
			'result' => is_array($data['result'] ?? null) ? $data['result'] : [],
		]);
	}

	private function createPageRules(string $path, array $queryParams): object
	{
		if ($this->isMobilePage($path))
		{
			return new MobilePageResolver($path, $queryParams);
		}

		return new PageRules($path, $queryParams);
	}

	private function isMobilePage(string $path): bool
	{
		return MobilePageResolver::isMobilePagePath($path);
	}

	private function getQueryParams($query): array
	{
		$params = [];

		if (!empty($query))
		{
			parse_str($query, $params);
		}

		return $params;
	}
}
