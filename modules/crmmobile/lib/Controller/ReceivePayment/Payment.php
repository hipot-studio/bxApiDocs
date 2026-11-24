<?php

namespace Bitrix\CrmMobile\Controller\ReceivePayment;

use Bitrix\Crm\Binding\EntityBinding;
use Bitrix\Crm\Controller\ErrorCode;
use Bitrix\Crm\Item;
use Bitrix\Crm\Service\Container;
use Bitrix\CrmMobile\Controller\Salescenter\Product2BasketItemConverter;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Sale\Label\Label;
use Bitrix\Salescenter\Analytics\Dictionary\SubSectionDictionary;
use Bitrix\SalesCenter\Controller\Order;
use Bitrix\SalesCenter\Integration\CrmManager;

Loader::requireModule('salescenter');

class Payment extends Base
{
	public function createPaymentAction(array $options): bool
	{
		$factory = Container::getInstance()->getFactory((int)$options['ownerTypeId']);
		if (!$factory)
		{
			$this->addErrors($this->markErrorsAsPublic([ErrorCode::getEntityTypeNotSupportedError()]));

			return false;
		}

		$entity = $factory->getItem((int)$options['ownerId']);
		if (!$entity)
		{
			$this->addErrors($this->markErrorsAsPublic([ErrorCode::getOwnerNotFoundError()]));

			return false;
		}

		if (!$this->bindSelectedContactIfAllowed($options, $entity))
		{
			return false;
		}

		$products = Product2BasketItemConverter::convert($options['products']);
		unset($options['products']);
		$options['orderId'] = CrmManager::getOrderIdByEntity($entity);
		$options['paymentLabels'] = [
			new Label('subSection', SubSectionDictionary::MOBILE->value)
		];

		$this->forward(
			Order::class,
			'createPayment',
			[
				'basketItems' => $products,
				'options' => $options,
			]
		);

		$errors = $this->getErrors();
		if (!empty($errors))
		{
			$this->errorCollection->clear();
			$errors = $this->markErrorsAsPublic($errors);
			$this->addErrors($errors);
		}

		return true;
	}

	private function bindSelectedContactIfAllowed(array $options, Item $entity): bool
	{
		if (!isset($options['selectedContact']))
		{
			return true;
		}

		if (!Container::getInstance()->getUserPermissions()->item()->canUpdateItem($entity))
		{
			$this->addErrors($this->markErrorsAsPublic([ErrorCode::getAccessDeniedError()]));

			return false;
		}

		$contactBinding = EntityBinding::prepareEntityBindings(
			\CCrmOwnerType::Contact,
			[(int)$options['selectedContact']['id']],
		);
		EntityBinding::markFirstAsPrimary($contactBinding);
		$entity->bindContacts($contactBinding);

		if (!$entity->save()->isSuccess())
		{
			$this->errorCollection->clear();
			$errors = [
				new Error(Loc::getMessage('RECEIVE_PAYMENT_SAVE_CONTACT_ERROR')),
			];
			$errors = $this->markErrorsAsPublic($errors);
			$this->addErrors($errors);
		}

		return true;
	}

	public function resendMessageAction(array $options): bool
	{
		$factory = Container::getInstance()->getFactory((int)$options['ownerTypeId']);
		if (!$factory)
		{
			return false;
		}

		$entity = $factory->getItem((int)$options['ownerId']);
		if (!$entity)
		{
			return false;
		}

		if (!$this->bindSelectedContactIfAllowed($options, $entity))
		{
			return false;
		}

		$orderId = CrmManager::getOrderIdByEntity($entity);

		$this->forward(
			Order::class,
			'resendPayment',
			[
				'orderId' => $orderId,
				'paymentId' => $options['paymentId'],
				'shipmentId' => $options['shipmentId'],
				'options' => $options,
			]
		);

		$errors = $this->getErrors();
		if (!empty($errors))
		{
			$this->errorCollection->clear();
			$errors = $this->markErrorsAsPublic($errors);
			$this->addErrors($errors);
		}

		return true;
	}
}
