<?php

use Bitrix\Crm\Feature;
use Bitrix\Crm\Integration\NotificationsManager;
use Bitrix\Crm\Integration\SmsManager;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Security\Random;
use Bitrix\Main\Application;

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
		$this->arResult['serviceUrl'] = "/bitrix/components/bitrix/crm.timeline/ajax.php?&site=".SITE_ID."&".bitrix_sessid_get();
		$this->arResult['ownerTypeId'] = $this->entityTypeId;
		$this->arResult['ownerId'] = $this->entityId;
		$this->arResult['providerId'] = $providerId;
		$this->arResult['isProviderFixed'] = (string)($this->arParams['IS_PROVIDER_FIXED'] ?? null) === 'Y';

		if (Feature::enabled(Feature\MessageSenderEditor::class))
		{
			$this->arResult['messageSenderSceneId'] = $this->arParams['MESSAGE_SENDER_SCENE_ID'] ?? '';
			$this->arResult['analytics'] = $this->arParams['ANALYTICS'] ?? [];
		}

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
		$config = SmsManager::getEditorConfig($this->entityTypeId, $this->entityId);
		$config['selectedProviderId'] = $this->getProviderId();

		if ($this->getProviderId() === NotificationsManager::getSenderCode())
		{
			// @todo if more flexible configuration is required, then need to create a getEditorConfig method for NotificationsManager
			$config['canSendMessage'] = NotificationsManager::canSendMessage();
		}

		if ($this->canUseBitrix24Provider())
		{
			$userId = Container::getInstance()->getContext()->getUserId();
			$channelsList = NotificationsManager::getChannelsList([], $userId);
			foreach($channelsList as $sender)
			{
				$fromList = [];
				foreach ($sender->getFromList() as $from)
				{
					$fromList[] = [
						'id' => $from->getId(),
						'name' => $from->getName(),
						'isDefault' => $from->isDefault(),
					];
				}

				$config['senders'][] = [
					'id' => $sender->getId(),
					'isConfigurable' => false,
					'name' => $sender->getShortName(),
					'shortName' => $sender->getShortName(),
					'canUse' => NotificationsManager::canUse(),
					'isDemo' => false,
					'isDefault' => $sender->isDefault(),
					'fromList' => $fromList,
					'isTemplatesBased' => true,
				];
			}

			$template = $this->getUnsignedTemplate();
			if ($template)
			{
				$config['templateCode'] = $template['template'];
				$config['templatePlaceholders'] = $template['placeholders'];
			}
		}

		$config['isEditable'] = $this->isEditable();
		$config['linkToMarket'] = \Bitrix\Crm\Integration\Market\Router::getCategoryPath('crm_robot_sms');

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

	private function isEditable(): bool
	{
		return ($this->arParams['IS_EDITABLE'] ?? 'Y') === 'Y';
	}

	private function getUnsignedTemplate(): ?array
	{
		if (!Feature::enabled(Feature\MessageSenderEditor::class))
		{
			if (!$this->arParams['SIGNED_TEMPLATE'])
			{
				return null;
			}

			$signedTemplate = NotificationsManager::unsignTemplate($this->arParams['SIGNED_TEMPLATE']);

			$result = [
				'template' => $signedTemplate['template'],
			];
			foreach ($signedTemplate['placeholders'] as $templatePlaceholder)
			{
				$result['placeholders'][$templatePlaceholder['name']] = $templatePlaceholder['value'];
			}

			return $result;
		}

		if (!$this->arParams['SIGNED_TEMPLATE'])
		{
			return null;
		}

		return NotificationsManager::unsignTemplate($this->arParams['SIGNED_TEMPLATE']);
	}
}
