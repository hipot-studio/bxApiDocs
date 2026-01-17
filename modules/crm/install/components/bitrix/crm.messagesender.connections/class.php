<?php

use Bitrix\Crm\Service\Container;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

class CrmMessagesenderConnectionsComponent extends \Bitrix\Crm\Component\Base
{
	private \Bitrix\Crm\MessageSender\UI\ConnectionsSlider $slider;

	protected function init(): void
	{
		parent::init();

		if (!$this->userPermissions->messageSender()->canConfigureChannels())
		{
			$this->addError(\Bitrix\Crm\Controller\ErrorCode::getAccessDeniedError());

			return;
		}

		$this->slider = \Bitrix\Crm\MessageSender\UI\Factory::getInstance()->createConnectionsSlider();
	}

	public function executeComponent(): void
	{
		$this->init();
		if ($this->getErrors())
		{
			ShowError(implode("\n", $this->getErrorMessages()));

			return;
		}

		$this->prepareResult();

		$this->includeComponentTemplate();
	}

	private function prepareResult(): void
	{
		$this->arResult['SLIDER'] = $this->slider;

		$firstPage = $this->slider->getPages()[0] ?? null;
		$this->arResult['CURRENT_PAGE_ID'] = $firstPage?->getId();
		$this->arResult['CONTACT_CENTER_URL'] = Container::getInstance()->getRouter()->getContactCenterUrl();
		$this->arResult['ANALYTICS'] = [
			'c_section' => $this->arParams['analytics']['c_section'] ?? null,
		];
	}
}
