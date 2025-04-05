<?php

namespace Bitrix\Crm\Integration\BizProc\Document\ValueCollection;

use Bitrix\Crm;
use Bitrix\Main;

class Order extends Base
{
	protected $order;
	protected $contactDocument;
	protected $companyDocument;

	protected function processField(string $fieldId): bool
	{
		if (strpos($fieldId, 'CONTACT.') === 0)
		{
			$this->loadContactFieldValue($fieldId);

			return true;
		}

		if (strpos($fieldId, 'COMPANY.') === 0)
		{
			$this->loadCompanyFieldValue($fieldId);

			return true;
		}

		if (strpos($fieldId, 'SHOP_') === 0)
		{
			$this->loadShopValues();

			return true;
		}

		if (strpos($fieldId, 'SHIPPING.') === 0)
		{
			$this->loadShippingValues();

			return true;
		}

		if (strpos($fieldId, 'RESPONSIBLE_ID.') === 0)
		{
			$this->loadAssignedByValues('RESPONSIBLE_ID', 'RESPONSIBLE_ID', false);

			return true;
		}

		return false;
	}

	protected function getOrder(): ?Crm\Order\Order
	{
		if ($this->order === null)
		{
			$list = Crm\Order\Order::loadByFilter([
				'filter' => ['ID' => $this->id],
				'select' => $this->select
			]);
			if (!empty($list) && is_array($list))
			{
				$this->order = reset($list);

				return $this->order;
			}

			$this->order = null;
		}

		return $this->order;
	}

	protected function loadEntityValues(): void
	{
		if (isset($this->document['ID']))
		{
			return;
		}

		$order = $this->getOrder();

		if (!$order)
		{
			return;
		}

		$fields = $order->getFieldValues();

		$userKeys = [
			'USER_ID', 'EMP_PAYED_ID', 'EMP_DEDUCTED_ID', 'EMP_STATUS_ID', 'EMP_MARKED_ID',
			'EMP_ALLOW_DELIVERY_ID', 'CREATED_BY', 'RESPONSIBLE_ID', 'EMP_CANCELED_ID',
		];
		foreach ($userKeys as $userKey)
		{
			if (isset($fields[$userKey]))
			{
				$fields[$userKey] = 'user_' . $fields[$userKey];
			}
		}

		$dbRes = Crm\Order\ContactCompanyCollection::getList([
			'select' => ['ENTITY_ID', 'ENTITY_TYPE_ID'],
			'filter' => [
				'=ORDER_ID' => $this->id,
				'@ENTITY_TYPE_ID' => [\CCrmOwnerType::Contact, \CCrmOwnerType::Company],
				'IS_PRIMARY' => 'Y',
			],
			'order' => ['ENTITY_TYPE_ID' => 'ASC'],
		]);
		while ($row = $dbRes->fetch())
		{
			if ((int)$row['ENTITY_TYPE_ID'] === \CCrmOwnerType::Contact)
			{
				$fields['CONTACT_ID'] = $row['ENTITY_ID'];
			}
			else
			{
				$fields['COMPANY_ID'] = $row['ENTITY_ID'];
			}
		}

		$fields['LID_PRINTABLE'] = $fields['LID'];
		if ($siteResult = \CSite::GetByID($fields['LID']))
		{
			$site = $siteResult->fetch();
			$fields['LID_PRINTABLE'] = $site['NAME'];
		}

		$fields['PRICE_FORMATTED'] = html_entity_decode(
			\CCrmCurrency::MoneyToString($fields['PRICE'], $fields['CURRENCY']),
			ENT_NOQUOTES,
			LANG_CHARSET
		);

		self::convertDateFields($fields);

		$this->document = array_merge($this->document, $fields ?: []);
		$this->normalizeEntityBindings(['COMPANY_ID', 'CONTACT_ID']);
		$this->loadUserFieldValues();
	}

	protected function loadContactFieldValue($fieldId): void
	{
		if ($this->contactDocument === null)
		{
			$this->loadEntityValues();
			if ($this->document['CONTACT_ID'])
			{
				$this->contactDocument = \CCrmDocumentContact::getDocument('CONTACT_' . $this->document['CONTACT_ID']);
			}
		}

		if ($this->contactDocument)
		{
			$contactFieldId = substr($fieldId, strlen('CONTACT.'));
			$this->document[$fieldId] = $this->contactDocument[$contactFieldId];
		}
	}

	protected function loadCompanyFieldValue($fieldId): void
	{
		if ($this->companyDocument === null)
		{
			$this->loadEntityValues();
			if ($this->document['COMPANY_ID'])
			{
				$this->companyDocument = \CCrmDocumentCompany::GetDocument('COMPANY_' . $this->document['COMPANY_ID']);
			}
		}

		if ($this->companyDocument)
		{
			$companyFieldId = substr($fieldId, strlen('COMPANY.'));
			$this->document[$fieldId] = $this->companyDocument[$companyFieldId];
		}
	}

	protected function loadShippingValues(): void
	{
		$order = $this->getOrder();
		if (!$order)
		{
			return;
		}

		$this->document['SHIPPING.ALL.TRACKING_NUMBER'] = [];

		$collection = $order->getShipmentCollection()->getNotSystemItems();

		/** @var \Bitrix\Sale\Shipment $shipment */
		foreach ($collection as $shipment)
		{
			if ($num = $shipment->getField('TRACKING_NUMBER'))
			{
				$this->document['SHIPPING.ALL.TRACKING_NUMBER'][] = $num;
			}
		}
	}

	protected function loadShopValues(): void
	{
		$order = $this->getOrder();
		if (!$order)
		{
			return;
		}

		$collection = $order->getTradeBindingCollection();

		/** @var Crm\Order\TradeBindingEntity $entity */
		foreach ($collection as $entity)
		{
			$platform = $entity->getTradePlatform();
			if ($platform === null)
			{
				continue;
			}

			$data = $platform->getInfo();
			$this->document['SHOP_TITLE'] = $data['TITLE'] ?? '';
			$this->document['SHOP_PUBLIC_URL'] = $data['PUBLIC_URL'] ?? '';
			break;
		}

		if (empty($this->document['SHOP_TITLE']))
		{
			$siteData = Main\SiteTable::getList([
				'select' => ['LID', 'NAME', 'SITE_NAME'],
				'filter' => ['LID' => $order->getSiteId()],
			])->fetch();

			if ($siteData)
			{
				if ($siteData['SITE_NAME'])
				{
					$this->document['SHOP_TITLE'] = $siteData['SITE_NAME'];
				}
				else
				{
					$this->document['SHOP_TITLE'] = $siteData['NAME'];
				}
			}
		}
	}

	private static function convertDateFields(array &$fields)
	{
		foreach ($fields as $field => $value)
		{
			if ($value instanceof Main\Type\DateTime)
			{
				$fields[$field] = $value->format(Main\Type\Date::getFormat());
			}
		}
	}
}
