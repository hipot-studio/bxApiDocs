<?php

namespace Bitrix\SalesCenter\Controller;

use Bitrix\Catalog\v2\IoC\ServiceContainer;
use Bitrix\Crm\Entity\Contact;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Timeline;
use Bitrix\ImOpenLines\Im;
use Bitrix\Main;
use Bitrix\Main\Engine\Action;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\SalesCenter\Integration\CatalogManager;
use Bitrix\SalesCenter\Integration\CrmManager;
use Bitrix\SalesCenter\Integration\ImOpenLinesManager;
use Bitrix\Crm\Binding\OrderEntityTable;

Loc::loadMessages(__FILE__);

class Compilation extends Base
{
	protected function processBeforeAction(Action $action): bool
	{
		if (!$this->checkModules())
		{
			return false;
		}

		return parent::processBeforeAction($action);
	}

	private function checkModules(): bool
	{
		if (!Main\Loader::includeModule('crm'))
		{
			$this->addError(new Main\Error('module "crm" is not installed.'));
			return false;
		}

		return true;
	}

	private function createDealForCompilation(array $options = []): ?int
	{
		$dealFields = [
			'ASSIGNED_BY_ID' => \CCrmSecurityHelper::GetCurrentUserID(),
			'OPPORTUNITY' => 0,
		];
		$clientInfo = (new \Bitrix\SalesCenter\Controller\Order())->getClientInfo($options);
		if (isset($clientInfo['CONTACT_IDS']))
		{
			$dealFields['CONTACT_IDS'] = $clientInfo['CONTACT_IDS'];
		}
		if (isset($clientInfo['COMPANY_ID']))
		{
			$dealFields['COMPANY_ID'] = $clientInfo['COMPANY_ID'];
		}
		if (!isset($clientInfo['CONTACT_IDS']) && !isset($clientInfo['COMPANY_ID']))
		{
			$dealFields['CONTACT_ID'] = Contact::getInstance()->create(['SOURCE_ID' => 'STORE']);
		}

		$deal = new \CCrmDeal(false);
		$options = [
			'DISABLE_USER_FIELD_CHECK' => true,
		];

		return $deal->Add($dealFields, true, $options) ?: null;
	}

	public function normalizeChatId($chatId): ?int
	{
		if (mb_strpos($chatId, 'chat') === 0)
		{
			return (int)mb_substr($chatId, 4);
		}

		return null;
	}

	public function createCompilationAction(array $productIds = [], array $options = []): array
	{
		if (!isset($options['ownerId']) || (int)$options['ownerTypeId'] !== \CCrmOwnerType::Deal)
		{
			return [];
		}

		$dealId = (int)$options['ownerId'];
		$dialogId = $options['dialogId'] ?? null;
		$chatId =$this->normalizeChatId($dialogId);

		if (!$dealId || !CrmManager::getInstance()->isOwnerEntityExists($dealId, \CCrmOwnerType::Deal))
		{
			$dealId = $this->createDealForCompilation($options);
			$this->onAfterDealAdd($dealId, (int)$options['sessionId']);
			ImOpenLinesManager::getInstance()->sendDealNotify($dealId, $dialogId);
		}

		$compilationId = CatalogManager::getInstance()->createCompilationForDeal($dealId, $productIds, $chatId);
		$compilationLink = CatalogManager::getInstance()->getLinkToProductCompilation($compilationId, $productIds)->getData();

		return [
			'link' => $compilationLink['link'],
			'compilationId' => $compilationId,
			'ownerId' => $dealId,
		];
	}

	public function updateCompilationAction($compilationId, $productIds = []): void
	{
		CatalogManager::getInstance()->setCompilationProducts((int)$compilationId, $productIds);
	}

	public function sendCompilationAction(array $productIds, array $options = []): array
	{
		$data = [];

		if (!isset($options['ownerId']) || (int)$options['ownerTypeId'] !== \CCrmOwnerType::Deal)
		{
			return $data;
		}

		$dealId = (int)$options['ownerId'];
		$isNewDeal = false;

		$dialogId = $options['dialogId'] ?? null;
		$chatId = $this->normalizeChatId($dialogId);

		if (!$dealId || !CrmManager::getInstance()->isOwnerEntityExists($dealId, \CCrmOwnerType::Deal))
		{
			$dealId = $this->createDealForCompilation($options);
			$this->onAfterDealAdd($dealId, $options['sessionId']);
			$isNewDeal = true;
		}

		if (isset($options['stageOnOrderPaid']))
		{
			CrmManager::getInstance()->saveTriggerOnOrderPaid(
				$dealId,
				\CCrmOwnerType::Deal,
				$options['stageOnOrderPaid']
			);
		}

		if (isset($options['stageOnDeliveryFinished']))
		{
			CrmManager::getInstance()->saveTriggerOnDeliveryFinished(
				$dealId,
				\CCrmOwnerType::Deal,
				$options['stageOnDeliveryFinished']
			);
		}

		$compilationId = null;
		if ($options['compilationId'])
		{
			$productCompilation = CatalogManager::getInstance()->getCompilationById((int)$options['compilationId']);
			if ($productCompilation)
			{
				$compilationId = (int)$productCompilation['ID'];
				if ($options['editable'] && $options['editable'] === 'true')
				{
					CatalogManager::getInstance()->setCompilationProducts((int)$options['compilationId'], $productIds);
				}
			}
		}

		if (!$compilationId)
		{
			$compilationId = CatalogManager::getInstance()->createCompilationForDeal($dealId, $productIds, $chatId);
		}
		if ($compilationId)
		{
			$compilation = CatalogManager::getInstance()->getCompilationById($compilationId);
			if (isset($compilation['PRODUCT_IDS']))
			{
				$productIds = $compilation['PRODUCT_IDS'];
			}
		}

		$compilationLink = CatalogManager::getInstance()->getLinkToProductCompilation($compilationId, $productIds)->getData();

		if ($options['sendingMethod'] === 'sms')
		{
			$isSent = CrmManager::getInstance()->sendCompilationBySms(
				$compilationId,
				$dealId,
				$compilationLink,
				$options['sendingMethodDesc'],
				$options['messageData'] ?? null,
			);
			if (!$isSent)
			{
				$this->addError(
					new Error(Loc::getMessage('SALESCENTER_CONTROLLER_COMPILATION_SEND_SMS_ERROR'))
				);
			}
		}
		elseif ($options['dialogId'])
		{
			$r = new Main\Result();
			if ($dealId && $isNewDeal)
			{
				$r = ImOpenLinesManager::getInstance()->sendDealNotify($dealId, $options['dialogId']);
			}

			if (!$r->isSuccess())
			{
				$this->addErrors($r->getErrors());
			}

			if (!isset($options['skipPublicMessage']) || $options['skipPublicMessage'] === 'n')
			{
				if ($compilationLink)
				{
					$result = ImOpenLinesManager::getInstance()->sendCompilationMessage($compilationLink, $options['dialogId'], $dealId);
					if ($result->isSuccess())
					{
						$this->onAfterCompilationSent($compilationId, $productIds, $dealId, $options['dialogId']);
					}
					else
					{
						$this->addErrors($result->getErrors());
					}
				}
			}
		}
		else
		{
			$messageDataBody = $options['messageData']['body'] ?? null;
			if ($messageDataBody)
			{
				if (Container::getInstance()->getUserPermissions()->isCrmAdmin())
				{
					CrmManager::getInstance()->saveSmsTemplate($messageDataBody);
				}
				$smsTemplate = $messageDataBody;
			}
			else
			{
				$smsTemplate = $options['sendingMethodDesc']['text'];
			}
			$smsTitle = str_replace('#LINK#', $compilationLink['link'], $smsTemplate);
			$data['compilation']['title'] = $smsTitle;
			$data['compilation']['url'] = $compilationLink['link'];
			$data['compilation']['productIds'] = $compilationLink['productIds'];
		}

		return $data;
	}

	private function onAfterCompilationSent($compilationId, $productIds, $dealId, $dialogId): void
	{
		$compilationProducts = CatalogManager::getInstance()->getProductVariations($productIds);

		$timelineParams = [
			'SETTINGS' => [
				'DEAL_ID' => $dealId,
				'SENT_PRODUCTS' => $compilationProducts,
				'COMPILATION_ID' => $compilationId,
			]
		];

		Timeline\ProductCompilationController::getInstance()->onCompilationSent($dealId, $timelineParams);

		$dealIsReorder = !empty(OrderEntityTable::getOrderIdsByOwner($dealId, \CCrmOwnerType::Deal));
		if ($dealIsReorder)
		{
			ImOpenLinesManager::getInstance()->sendReorderNotification($dialogId);
		}
	}

	private function onAfterDealAdd(int $dealId, int $sessionId): void
	{
		ImOpenLinesManager::getInstance()->updateDealAfterCreation($dealId, $sessionId);
	}
}
