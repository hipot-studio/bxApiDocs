<?php

use Bitrix\Crm\Integration\NotificationsManager;
use Bitrix\Crm\Integration\SmsManager;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Security\Random;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

class CCrmSmsSendComponent extends CBitrixComponent
{
	protected $entityTypeId;
	protected $entityId;

	public function onPrepareComponentParams($arParams)
	{
		$arParams = parent::onPrepareComponentParams($arParams);

		$this->entityTypeId = $arParams['ENTITY_TYPE_ID'];
		$this->entityId = $arParams['ENTITY_ID'];

		return $arParams;
	}

	public function executeComponent()
	{
		Loader::includeModule('crm');

		Loc::loadLanguageFile(__FILE__);

		$messageSender = Container::getInstance()->getUserPermissions()->messageSender();
		$providerId = $this->getProviderId();
		if (
			!$messageSender->canSend($this->entityTypeId,  $this->entityId)
			|| ($providerId === SmsManager::getSenderCode() && !SmsManager::canUse())
			|| (
				$this->canUseBitrix24Provider()
				&& $providerId === NotificationsManager::getSenderCode()
				&& !NotificationsManager::canUse()
			)
		)
		{
			ShowError(Loc::getMessage('CRM_SMS_SEND_COMPONENT_NOT_AVAILABLE'));

			return;
		}

		$this->arResult = $this->getConfig();
		$this->arResult['text'] = $this->arParams['TEXT'];
		$this->arResult['containerId'] = 'sms_send_' . Random::getString(10);
		$this->arResult['ownerTypeId'] = $this->entityTypeId;
		$this->arResult['ownerId'] = $this->entityId;

		$this->arResult['messageSenderSceneId'] = $this->arParams['MESSAGE_SENDER_SCENE_ID'] ?? '';
		$this->arResult['analytics'] = $this->arParams['ANALYTICS'] ?? [];

		global $APPLICATION;
		$APPLICATION->SetTitle(Loc::getMessage('CRM_SMS_SEND_COMPONENT_TITLE_MSGVER_1'));
		if (Loader::includeModule('ui'))
		{
			\Bitrix\UI\Toolbar\Facade\Toolbar::deleteFavoriteStar();
		}

		$this->includeComponentTemplate();
	}

	private function getProviderId(): ?string
	{
		return $this->arParams['PROVIDER_ID'] ?? null;
	}

	private function getConfig(): array
	{
		$config = [];

		if ($this->canUseBitrix24Provider())
		{
			$template = $this->getUnsignedTemplate();
			if ($template)
			{
				$config['templateCode'] = $template['template'];
				$config['templatePlaceholders'] = $template['placeholders'];
			}
		}

		return $config;
	}

	private function canUseBitrix24Provider(): bool
	{
		if (Application::getInstance()->getLicense()->getRegion() !== 'ru')
		{
			return false;
		}

		return ($this->arParams['CAN_USE_BITRIX24_PROVIDER'] ?? 'N') === 'Y';
	}

	private function getUnsignedTemplate(): ?array
	{
		if (!$this->arParams['SIGNED_TEMPLATE'])
		{
			return null;
		}

		return NotificationsManager::unsignTemplate($this->arParams['SIGNED_TEMPLATE']);
	}
}
