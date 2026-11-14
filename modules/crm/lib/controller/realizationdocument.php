<?php

namespace Bitrix\Crm\Controller;

use Bitrix\Catalog\Config\State;
use Bitrix\Main;
use Bitrix\Crm;
use Bitrix\Sale;
use Bitrix\Catalog;
use Bitrix\Catalog\Access\AccessController;
use Bitrix\Catalog\Access\ActionDictionary;
use Bitrix\Catalog\Access\Model\StoreDocument;
use Bitrix\Crm\Reservation\Validator\ExistingStore;
use Bitrix\Crm\Service\Sale\Reservation\ShipmentService;
use Bitrix\Main\Loader;

Main\Localization\Loc::loadLanguageFile(__FILE__);

class RealizationDocument extends Main\Engine\Controller
{
	private const REALIZATION_ACCESS_DENIED_ERROR_CODE = 'REALIZATION_ACCESS_DENIED';
	private const REALIZATION_CANNOT_DELETE_ERROR_CODE = 'REALIZATION_CANNOT_DELETE';
	private const REALIZATION_ALREADY_DEDUCTED_ERROR_CODE = 'REALIZATION_ALREADY_DEDUCTED';
	private const REALIZATION_NOT_DEDUCTED_ERROR_CODE = 'REALIZATION_NOT_DEDUCTED';
	private const REALIZATION_PRODUCT_NOT_FOUND_ERROR_CODE = 'REALIZATION_PRODUCT_NOT_FOUND';
	private const REALIZATION_NOT_USED_INVENTORY_MANAGEMENT_ERROR_CODE = 'REALIZATION_NOT_USED_INVENTORY_MANAGEMENT';
	private const REALIZATION_STORE_DELETED_ERROR_CODE = 'REALIZATION_STORE_DELETED';

	/** @var int $defaultStoreId */
	private $defaultStoreId;

	private $needEnableAutomation = false;

	private AccessController $accessController;

	/**
	 * @inheritDoc
	 */
	protected function init()
	{
		parent::init();

		Loader::requireModule('catalog');

		$this->accessController = AccessController::getCurrent();
	}

	protected function processBeforeAction(Main\Engine\Action $action)
	{
		if (!Main\Loader::includeModule('sale'))
		{
			return false;
		}

		if (!$this->checkReadPermission())
		{
			return false;
		}

		$this->defaultStoreId = Catalog\StoreTable::getDefaultStoreId();

		if (Sale\Configuration::isEnableAutomaticReservation())
		{
			Sale\Configuration::disableAutomaticReservation();
			$this->needEnableAutomation = true;
		}

		return parent::processBeforeAction($action);
	}

	protected function processAfterAction(Main\Engine\Action $action, $result)
	{
		if ($this->needEnableAutomation)
		{
			Sale\Configuration::enableAutomaticReservation();
		}

		parent::processAfterAction($action, $result);
	}

	/**
	 * Sets/unsets shipment as realization
	 *
	 * @param int $id
	 * @param string $value
	 * @return void
	 */
	public function setRealizationAction(int $id, string $value): void
	{
		if ($value === 'Y' && !$this->checkModifyPermission($id))
		{
			return;
		}
		elseif ($value === 'N' && !$this->checkPermission(ActionDictionary::ACTION_STORE_DOCUMENT_DELETE, $id))
		{
			return;
		}

		$shipmentResult = $this->prepareShipment($id);
		if (!$shipmentResult->isSuccess())
		{
			$this->addErrors($shipmentResult->getErrors());
			return;
		}

		$shipmentData = $shipmentResult->getData();
		/** @var Crm\Order\Shipment $shipment */
		$shipment = $shipmentData['shipment'];
		/** @var Crm\Order\Order $order */
		$order = $shipmentData['order'];

		if ($value === 'N' && !$this->checkReadAnyStorePermission($shipment))
		{
			$this->addError(
				$this->getDeletedStoreError($shipment)
				?? new Main\Error(
					Main\Localization\Loc::getMessage(
						'CRM_CONTROLLER_REALIZATION_DOCUMENT_DELETE_ERROR',
						[
							'#ID#' => htmlspecialcharsbx($shipment->getField('ACCOUNT_NUMBER')),
						],
					),
					self::REALIZATION_CANNOT_DELETE_ERROR_CODE,
				),
			);

			return;
		}

		$delivery = Sale\Delivery\Services\Manager::getById($shipment->getDeliveryId());
		$isEmptyDeliveryService = (
			$delivery['CLASS_NAME'] === '\\' . Sale\Delivery\Services\EmptyDeliveryService::class
			|| is_subclass_of($delivery['CLASS_NAME'], Sale\Delivery\Services\EmptyDeliveryService::class)
		);

		if ($value === 'N')
		{
			if ($shipment->isShipped())
			{
				$this->addError(
					new Main\Error(
						Main\Localization\Loc::getMessage(
							'CRM_CONTROLLER_REALIZATION_DOCUMENT_DELETE_DEDUCTED_ERROR',
							[
								'#ID#' => htmlspecialcharsbx($shipment->getField('ACCOUNT_NUMBER')),
							]
						),
						self::REALIZATION_CANNOT_DELETE_ERROR_CODE
					)
				);
			}
			elseif (
				$this->isSetRealizationDocumentTradeBinding($order)
				&& $order->getShipmentCollection()->getNotSystemItems()->count() === 1
				&& $order->getPaymentCollection()->count() === 0
			)
			{
				$deleteOrder = Crm\Order\Order::delete($order->getId());
				if (!$deleteOrder->isSuccess())
				{
					$this->addErrors($deleteOrder->getErrors());
				}

				unset($order);
			}
			elseif ($isEmptyDeliveryService)
			{
				$deleteShipmentResult = $shipment->delete();
				if (!$deleteShipmentResult->isSuccess())
				{
					$this->addErrors($deleteShipmentResult->getErrors());
				}
			}
			else
			{
				$setResult = $shipment->setField('IS_REALIZATION', $value);
				if (!$setResult->isSuccess())
				{
					$this->addErrors($setResult->getErrors());
				}
			}
		}
		else
		{
			$setResult = $shipment->setField('IS_REALIZATION', $value);
			if (!$setResult->isSuccess())
			{
				$this->addErrors($setResult->getErrors());
			}
		}

		if (isset($order) && $this->errorCollection->isEmpty())
		{
			$saveOrderResult = $order->save();
			if (!$saveOrderResult->isSuccess())
			{
				$this->addErrors($saveOrderResult->getErrors());
			}
		}
	}

	/**
	 * Sets/unsets shipments as realization
	 *
	 * @param array $ids
	 * @param string $value
	 * @return void
	 */
	public function setRealizationListAction(array $ids, string $value)
	{
		if ($value === 'Y' && !$this->checkModifyPermission())
		{
			return;
		}
		elseif ($value === 'N' && !$this->checkPermission(ActionDictionary::ACTION_STORE_DOCUMENT_DELETE))
		{
			return;
		}

		$result = new Main\Result();

		foreach ($ids as $id)
		{
			$this->setRealizationAction($id, $value);

			if (!$this->errorCollection->isEmpty())
			{
				foreach ($this->errorCollection as $error)
				{
					$result->addError($error);
				}

				$this->errorCollection->clear();
			}
		}

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());
		}
	}

	/**
	 * Sets shipped or unshipped realization
	 *
	 * @param int $id
	 * @param string $value
	 * @return array|null `['warnings' => string[]]` when the cancellation left reserves unrestored, otherwise null
	 */
	public function setShippedAction(int $id, string $value): ?array
	{
		if (!State::isUsedInventoryManagement())
		{
			$this->addError(
				new Main\Error(
					Main\Localization\Loc::getMessage('CRM_CONTROLLER_REALIZATION_DOCUMENT_NOT_USED_INVENTORY_MANAGEMENT'),
					self::REALIZATION_NOT_USED_INVENTORY_MANAGEMENT_ERROR_CODE
				)
			);

			return null;
		}

		$accessAction =
			$value === 'Y'
				? ActionDictionary::ACTION_STORE_DOCUMENT_CONDUCT
				: ActionDictionary::ACTION_STORE_DOCUMENT_CANCEL
		;
		if (!$this->checkPermission($accessAction, $id))
		{
			return null;
		}

		$shipmentResult = $this->prepareShipment($id);
		if (!$shipmentResult->isSuccess())
		{
			$this->addErrors($shipmentResult->getErrors());

			return null;
		}

		$shipmentData = $shipmentResult->getData();
		/** @var Crm\Order\Shipment $shipment */
		$shipment = $shipmentData['shipment'];
		/** @var Crm\Order\Order $order */
		$order = $shipmentData['order'];

		if ($value === 'Y' && $shipment->isShipped())
		{
			$this->addError(
				new Main\Error(
					Main\Localization\Loc::getMessage(
						'CRM_CONTROLLER_REALIZATION_DOCUMENT_SHIP_DEDUCTED_ERROR',
						[
							'#ID#' => htmlspecialcharsbx($shipment->getField('ACCOUNT_NUMBER')),
						]
					),
					self::REALIZATION_ALREADY_DEDUCTED_ERROR_CODE,
				)
			);
		}
		elseif ($value === 'N' && !$shipment->isShipped())
		{
			$this->addError(
				new Main\Error(
					Main\Localization\Loc::getMessage(
						'CRM_CONTROLLER_REALIZATION_DOCUMENT_UNSHIP_UNDEDUCTED_ERROR',
						[
							'#ID#' => htmlspecialcharsbx($shipment->getField('ACCOUNT_NUMBER')),
						]
					),
					self::REALIZATION_NOT_DEDUCTED_ERROR_CODE,
				)
			);
		}
		else
		{
			if ($value === 'Y')
			{
				if ($shipment->getShipmentItemCollection()->isEmpty())
				{
					$this->addError(
						new Main\Error(
							Main\Localization\Loc::getMessage('CRM_CONTROLLER_REALIZATION_DOCUMENT_PRODUCT_NOT_FOUND'),
							self::REALIZATION_PRODUCT_NOT_FOUND_ERROR_CODE,
						)
					);

					return null;
				}

				/** @var Crm\Order\ShipmentItem $shipmentItem */
				foreach ($shipment->getShipmentItemCollection() as $shipmentItem)
				{
					$shipmentItemStoreCollection = $shipmentItem->getShipmentItemStoreCollection();
					if ($shipmentItemStoreCollection && $shipmentItemStoreCollection->isEmpty())
					{
						$basketItem = $shipmentItem->getBasketItem();

						$reserveQuantityCollection = $basketItem->getReserveQuantityCollection();
						if ($reserveQuantityCollection && $reserveQuantityCollection->isEmpty())
						{
							$storeId = $this->defaultStoreId;
						}
						elseif ($reserveQuantityCollection && $reserveQuantityCollection->count() === 1)
						{
							/** @var \Bitrix\Sale\ReserveQuantity $reserveQuantity */
							$reserveQuantity = $reserveQuantityCollection->current();
							$storeId = $reserveQuantity->getStoreId();
						}
						else
						{
							break;
						}

						$shipmentItemStore = $shipmentItemStoreCollection->createItem($basketItem);
						$setFieldResult = $shipmentItemStore->setFields([
							'BASKET_ID' => $basketItem->getId(),
							'STORE_ID' => $storeId,
							'QUANTITY' => $shipmentItem->getQuantity(),
							'ORDER_DELIVERY_BASKET_ID' => $shipmentItem->getId(),
						]);
						if (!$setFieldResult->isSuccess())
						{
							$this->addErrors($setFieldResult->getErrors());
						}
					}
				}
			}

			if (!$this->checkReadAnyStorePermission($shipment))
			{
				$this->addError(
					$this->getDeletedStoreError($shipment)
					?? new Main\Error(
						Main\Localization\Loc::getMessage('CRM_CONTROLLER_REALIZATION_DOCUMENT_ACCESS_DENIED'),
						self::REALIZATION_ACCESS_DENIED_ERROR_CODE,
					),
				);

				return null;
			}

			$setResult = $shipment->setField('DEDUCTED', $value);
			if (!$setResult->isSuccess())
			{
				$this->addErrors($setResult->getErrors());
			}
		}

		$warnings = [];
		if ($this->errorCollection->isEmpty())
		{
			$saveOrderResult = $order->save();
			if ($saveOrderResult->isSuccess())
			{
				if ($value === 'N')
				{
					$reserveResult = ShipmentService::getInstance()->reserveCanceledShipment($shipment);
					$warnings = $reserveResult->getData()['WARNINGS'] ?? [];
				}
			}
			else
			{
				$this->addErrors($saveOrderResult->getErrors());
			}
		}
		else
		{
			if ($value === 'Y')
			{
				$shipmentResult = $this->prepareShipment($id);
				$shipmentData = $shipmentResult->getData();
				/** @var Crm\Order\Shipment $shipment */
				$shipment = $shipmentData['shipment'];
				/** @var Crm\Order\Order $order */
				$order = $shipmentData['order'];

				$setResult = $shipment->setField('IS_REALIZATION', $value);
				if ($setResult->isSuccess())
				{
					$saveOrderResult = $order->save();
					if (!$saveOrderResult->isSuccess())
					{
						$this->addErrors($saveOrderResult->getErrors());
					}
				}
			}
		}

		return empty($warnings) ? null : ['warnings' => array_values($warnings)];
	}

	/**
	 * Sets shipped or unshipped realizations
	 *
	 * @param array $ids
	 * @param string $value
	 * @return void
	 */
	public function setShippedListAction(array $ids, string $value): void
	{
		$accessAction =
			$value === 'Y'
				? ActionDictionary::ACTION_STORE_DOCUMENT_CONDUCT
				: ActionDictionary::ACTION_STORE_DOCUMENT_CANCEL
		;
		if (!$this->checkPermission($accessAction))
		{
			return;
		}

		$result = new Main\Result();

		foreach ($ids as $id)
		{
			$this->setShippedAction($id, $value);

			if (!$this->errorCollection->isEmpty())
			{
				foreach ($this->errorCollection as $error)
				{
					$result->addError($error);
				}

				$this->errorCollection->clear();
			}
		}

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());
		}
	}

	private function checkReadAnyStorePermission(Crm\Order\Shipment $shipment): bool
	{
		$storeIds = $this->getShipmentStoreIds($shipment);
		foreach ($storeIds as $storeId)
		{
			if ($this->accessController->checkByValue(ActionDictionary::ACTION_STORE_VIEW, $storeId))
			{
				return true;
			}
		}

		return empty($storeIds);
	}

	/**
	 * @return int[] unique ids of the stores the shipment is shipped from
	 */
	private function getShipmentStoreIds(Crm\Order\Shipment $shipment): array
	{
		$storeIds = [];

		/** @var Crm\Order\ShipmentItem $shipmentItem */
		foreach ($shipment->getShipmentItemCollection() as $shipmentItem)
		{
			$storeCollection = $shipmentItem->getShipmentItemStoreCollection();
			if (!$storeCollection)
			{
				continue;
			}

			foreach ($storeCollection as $store)
			{
				$storeId = (int)$store->getStoreId();
				if ($storeId > 0)
				{
					$storeIds[$storeId] = $storeId;
				}
			}
		}

		return array_values($storeIds);
	}

	/**
	 * @param int[] $storeIds
	 */
	private function isAllShipmentStoresDeleted(array $storeIds): bool
	{
		if (empty($storeIds))
		{
			return false;
		}

		return empty(array_diff($storeIds, ExistingStore::getMissingStoreIds($storeIds)));
	}

	/**
	 * The refusal of the store check when every store of the shipment has been deleted: only an employee with
	 * access to all stores may act on such a document.
	 */
	private function getDeletedStoreError(Crm\Order\Shipment $shipment): ?Main\Error
	{
		if (!$this->isAllShipmentStoresDeleted($this->getShipmentStoreIds($shipment)))
		{
			return null;
		}

		return new Main\Error(
			Main\Localization\Loc::getMessage(
				'CRM_CONTROLLER_REALIZATION_DOCUMENT_DELETED_STORE_ACCESS_DENIED',
				[
					'#ID#' => htmlspecialcharsbx($shipment->getField('ACCOUNT_NUMBER')),
				],
			),
			self::REALIZATION_STORE_DELETED_ERROR_CODE,
		);
	}

	/**
	 * Check permissions on create or update model.
	 *
	 * @param int $id
	 *
	 * @return bool
	 */
	private function checkModifyPermission(int $id = 0): bool
	{
		return $this->checkPermission(ActionDictionary::ACTION_STORE_DOCUMENT_MODIFY, $id);
	}

	private function checkReadPermission(): bool
	{
		return
			$this->checkPermission(ActionDictionary::ACTION_CATALOG_READ)
			&& $this->checkPermission(ActionDictionary::ACTION_INVENTORY_MANAGEMENT_ACCESS)
			&& $this->checkPermission(ActionDictionary::ACTION_STORE_DOCUMENT_VIEW)
		;
	}

	/**
	 * Check permissions.
	 *
	 * @param string $action
	 * @param int $id
	 *
	 * @return bool
	 */
	private function checkPermission(string $action, int $id = 0): bool
	{
		$can = $this->accessController->check($action, StoreDocument::createForSaleRealization($id));
		if (!$can)
		{
			$this->addError(
				new Main\Error(
					Main\Localization\Loc::getMessage('CRM_CONTROLLER_REALIZATION_DOCUMENT_ACCESS_DENIED'),
					self::REALIZATION_ACCESS_DENIED_ERROR_CODE
				)
			);
			return false;
		}

		return true;
	}

	private function prepareShipment(int $id): Main\Result
	{
		$result = new Main\Result();

		$shipment = Sale\Repository\ShipmentRepository::getInstance()->getById($id);
		if (!$shipment)
		{
			$result->addError(
				new Main\Error(
					Main\Localization\Loc::getMessage(
						'CRM_CONTROLLER_REALIZATION_DOCUMENT_SHIPMENT_NOT_FOUND_ERROR',
						[
							'#ID#' => $id,
						]
					)
				)
			);
			return $result;
		}

		$order = $shipment->getOrder();
		if (!$order)
		{
			$result->addError(
				new Main\Error(
					Main\Localization\Loc::getMessage(
						'CRM_CONTROLLER_REALIZATION_DOCUMENT_ORDER_NOT_FOUND_ERROR'
					)
				)
			);
			return $result;
		}

		$result->setData([
			'shipment' => $shipment,
			'order' => $order,
		]);

		return $result;
	}

	private function isSetRealizationDocumentTradeBinding(Crm\Order\Order $order): bool
	{
		$collection = $order->getTradeBindingCollection();
		/** @var Crm\Order\TradeBindingEntity $binding */
		foreach ($collection as $binding)
		{
			$platform = $binding->getTradePlatform();
			if (
				$platform
				&& $platform->getCode() === Crm\Order\TradingPlatform\RealizationDocument::TRADING_PLATFORM_CODE)
			{
				return true;
			}
		}

		return false;
	}
}
