<?php

use Bitrix\Intranet\CurrentUser;
use Bitrix\Intranet\License\Widget\Content\VibeBalance;
use Bitrix\Main\Application;
use Bitrix\Main\Loader;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

class IntranetLicenseWidgetComponent extends \CBitrixComponent
{
	public function executeComponent(): void
	{
		if (Loader::includeModule('extranet') && \CExtranet::IsExtranetSite())
		{
			return;
		}

		$this->arResult['skeleton'] = $this->getSkeleton();

		global $APPLICATION;
		$APPLICATION->SetPageProperty('HeaderClass', 'intranet-header--with-controls');
		$this->includeComponentTemplate();
	}

	private function getSkeleton(): array
	{
		$items = [];

		if (Application::getInstance()->getLicense()->isTimeBound())
		{
			$items[] = ['type' => 'item', 'height' => 55];
		}

		$vibeBalance = (new VibeBalance())->getConfiguration();
		$this->arResult['vibeBalance'] = $vibeBalance;
		if (
			($vibeBalance['isAvailable'] ?? false) === true
			&& (
				(($vibeBalance['balanceState'] ?? null) === 'available' && ($vibeBalance['balance'] ?? null) !== null)
				|| (($vibeBalance['balanceState'] ?? null) === 'unavailable' && !empty($vibeBalance['dashboardUrl']))
			)
		)
		{
			$items[] = ['type' => 'item', 'height' => 31];
		}

		$items[] = ['type' => 'item', 'height' => 31];
		$items[] = ['type' => 'item', 'height' => 26];
		$items[] = ['type' => 'split', 'height' => 19];

		return [
			'header' => false,
			'footer' => ['count' => 1],
			'items' => $items,
		];
	}
}
