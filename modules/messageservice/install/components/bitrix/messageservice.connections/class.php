<?php

use Bitrix\Main\DI\ServiceLocator;
use Bitrix\MessageService\Integration\ImOpenLines;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

class MessageServiceConnectionsComponent extends \CBitrixComponent
{
	public function executeComponent(): void
	{
		if (!\Bitrix\Main\Loader::includeModule('messageservice'))
		{
			ShowError('Module "messageservice" is not installed');

			return;
		}

		$this->prepareResult();

		$this->includeComponentTemplate();
	}

	private function prepareResult(): void
	{
		/** @var \Bitrix\MessageService\Public\UI\Factory $factory */
		$factory = ServiceLocator::getInstance()->get('messageservice.public.ui.factory');
		$slider = $factory->createConnectionsSlider();
		
		$this->arResult['SLIDER'] = $slider;

		$firstPage = $slider->getPages()[0] ?? null;
		$this->arResult['CURRENT_PAGE_ID'] = $firstPage?->getId();

		$this->arResult['CONTACT_CENTER_URL'] = ImOpenLines::getContactCenterUrl();

		$this->arResult['ANALYTICS'] = [
			'c_section' => $this->arParams['analytics']['c_section'] ?? null,
		];
	}
}
