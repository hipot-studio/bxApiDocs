<?php


namespace Bitrix\Market\ListTemplates;


use Bitrix\Main\Localization\Loc;
use Bitrix\Main\UI\PageNavigation;
use Bitrix\Market\AppFavoritesTable;
use Bitrix\Market\Categories;
use Bitrix\Market\NumberApps;
use Bitrix\Market\Rest\Actions;
use Bitrix\Rest\Marketplace\Transport;

class Favorites extends BaseTemplate
{
	public function setResult(bool $isAjax = false)
	{
		$title = Loc::getMessage('MARKET_FAVORITES_PAGE_TITLE');

		$this->result['TITLE'] = $title;

		global $APPLICATION;
		$APPLICATION->SetTitle($title);

		$this->result['CURRENT_APPS_CNT'] = count(AppFavoritesTable::getUserFavorites());

		$nav = new PageNavigation('market-favorites-nav');
		$nav->setPageSize(20)
			->setCurrentPage($this->page)
			->setRecordCount($this->result['CURRENT_APPS_CNT']);

		$this->result['CUR_PAGE'] = $nav->getCurrentPage();
		$this->result['PAGES'] = $nav->getPageCount();

		$this->result['ALL_CODES'] = AppFavoritesTable::getUserFavoritesForList($nav->getOffset(), $nav->getLimit());
		if (empty($this->result['ALL_CODES'])) {
			$this->result['APPS'] = [];
			return;
		}

		$batch = [
			Transport::METHOD_FILTER_APP => [
				Transport::METHOD_FILTER_APP,
				[
					'app_codes' => $this->result['ALL_CODES'],
					'_market_' => 'Y',
				],
			],
			Actions::METHOD_TOTAL_APPS => [
				Actions::METHOD_TOTAL_APPS,
			],
		];
		if (!$isAjax && empty(Categories::get())) {
			$batch[Actions::METHOD_GET_CATEGORIES_V2] = [Actions::METHOD_GET_CATEGORIES_V2];
		}

		$response = Transport::instance()->batch($batch);
		if (
			isset($response[Transport::METHOD_FILTER_APP]) &&
			isset($response[Transport::METHOD_FILTER_APP]['ITEMS']) &&
			is_array($response[Transport::METHOD_FILTER_APP]['ITEMS'])
		) {
			$this->prepareApps($response[Transport::METHOD_FILTER_APP]['ITEMS']);
		}

		if (!empty($response[Actions::METHOD_GET_CATEGORIES_V2])) {
			Categories::saveCache($response[Actions::METHOD_GET_CATEGORIES_V2]);
			$this->result['CATEGORIES'] = Categories::get();
		}

		$this->result['TOTAL_APPS'] = NumberApps::get($response[Actions::METHOD_TOTAL_APPS]);
		$this->result['SHOW_MARKET_ICON'] = $response[Actions::METHOD_TOTAL_APPS]['SHOW_MARKET_ICON'];
		$this->result['ADDITIONAL_CONTENT'] = $response[Actions::METHOD_TOTAL_APPS]['ADDITIONAL_CONTENT'] ?? '';
		$this->result['ADDITIONAL_MARKET_ACTION'] = $response[Actions::METHOD_TOTAL_APPS]['ADDITIONAL_MARKET_ACTION'] ?? '';
		$this->result['ADDITIONAL_SEARCH_ACTION'] = $response[Actions::METHOD_TOTAL_APPS]['ADDITIONAL_SEARCH_ACTION'] ?? '';
		$this->result['ADDITIONAL_HIT_ACTION'] = $response[Actions::METHOD_TOTAL_APPS]['ADDITIONAL_HIT_ACTION'] ?? '';
	}

	private function prepareApps($apps)
	{
		$publishedApps = [];
		foreach ($apps as $app) {
			$publishedApps[$app['CODE']] = $app;
		}

		foreach ($this->result['ALL_CODES'] as $appCode) {
			if (empty($publishedApps[$appCode])) {
				$this->result['APPS'][] = [
					'CODE' => $appCode,
					'UNPUBLISHED' => 'Y',
					'IS_FAVORITE' => 'Y',
				];
			} else {
				$this->result['APPS'][] = $publishedApps[$appCode];
			}
		}
	}
}