<?php

use Bitrix\Main\Application;
use Bitrix\Main\Localization\Loc;

if(!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED !== true)
{
	die;
}

class RestMarketplaceSolutionComponent extends CBitrixComponent
{
	protected function prepareResult(): void
	{
		$result = [
			'tags' => []
		];

		$region = Application::getInstance()->getLicense()->getRegion();
		if ($region === 'kz')
		{
			$trade = [
				'title' => Loc::getMessage('REST_MARKETPLACE_SOLUTION_TRADE_TITLE'),
				'description' => Loc::getMessage('REST_MARKETPLACE_SOLUTION_TRADE_DESCRIPTION'),
				'image' => '/bitrix/images/rest/solution/trade.svg',
				'tag' => 'продажи',
			];
			$result['tags'][] = $trade;

			$furniture = [
				'title' => Loc::getMessage('REST_MARKETPLACE_SOLUTION_FURNITURE_TITLE'),
				'description' => Loc::getMessage('REST_MARKETPLACE_SOLUTION_FURNITURE_DESCRIPTION'),
				'image' => '/bitrix/images/rest/solution/furniture.svg',
				'tag' => 'мебель',
			];
			$result['tags'][] = $furniture;

			$production = [
				'title' => Loc::getMessage('REST_MARKETPLACE_SOLUTION_PRODUCTION_TITLE'),
				'description' => Loc::getMessage('REST_MARKETPLACE_SOLUTION_PRODUCTION_DESCRIPTION'),
				'image' => '/bitrix/images/rest/solution/production.svg',
				'tag' => 'производство',
			];
			$result['tags'][] = $production;
		}
		else if (in_array($region, ['ru', 'by']))
		{
			$trade = [
				'title' => Loc::getMessage('REST_MARKETPLACE_SOLUTION_TRADE_TITLE'),
				'description' => Loc::getMessage('REST_MARKETPLACE_SOLUTION_TRADE_DESCRIPTION'),
				'image' => '/bitrix/images/rest/solution/trade.svg',
				'tag' => 'продажи',
			];
			$result['tags'][] = $trade;

			$build = [
				'title' => Loc::getMessage('REST_MARKETPLACE_SOLUTION_CONSTRUCTION_TITLE'),
				'description' => Loc::getMessage('REST_MARKETPLACE_SOLUTION_CONSTRUCTION_DESCRIPTION'),
				'image' => '/bitrix/images/rest/solution/build.svg',
				'tag' => 'строительство',
			];
			$result['tags'][] = $build;

			$tourism = [
				'title' => Loc::getMessage('REST_MARKETPLACE_SOLUTION_TOURISM_TITLE'),
				'description' => Loc::getMessage('REST_MARKETPLACE_SOLUTION_TOURISM_DESCRIPTION'),
				'image' => '/bitrix/images/rest/solution/tourism.svg',
				'tag' => 'туризм',
			];
			$result['tags'][] = $tourism;
		}

		$this->arResult = $result;
	}

	public function executeComponent(): void
	{
		$this->prepareResult();

		$this->includeComponentTemplate();
	}
}
