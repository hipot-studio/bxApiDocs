<?php

namespace Bitrix\Crm;

use Bitrix\Crm\Binding\DealContactTable;
use Bitrix\Crm\Binding\LeadContactTable;
use Bitrix\Crm\Order\Order;
use Bitrix\Crm\Service\Container;
use CCrmInvoice;
use CCrmOwnerType;

/**
 * Client info.
 *
 * Contains the ids of the company, contacts, as well as the owner.
 * To create instances, you can use the factory method @see ClientInfo::createFromOwner.
 */
class ClientInfo
{
	public ?int $companyId;
	public array $contactIds;
	public ?int $ownerTypeId = null;
	public ?int $ownerId = null;

	private static array $cache = [];

	/**
	 * @param int|null $companyId
	 * @param array $contactIds
	 */
	public function __construct(
		?int $companyId,
		array $contactIds
	)
	{
		$this->companyId = $companyId;
		$this->contactIds = $contactIds;
	}

	/**
	 * Return array with values.
	 *
	 * @param bool $withOwner if true, the owner values will be added, but only if it is set.
	 *
	 * @return array
	 */
	public function toArray(bool $withOwner = true): array
	{
		$result = [
			'COMPANY_ID' => $this->companyId,
			'CONTACT_IDS' => $this->contactIds,
		];

		if ($withOwner && isset($this->ownerTypeId) && isset($this->ownerId))
		{
			$result['OWNER_TYPE_ID'] = $this->ownerTypeId;
			$result['OWNER_ID'] = $this->ownerId;
		}

		return $result;
	}

	/**
	 * Gets true if client (contacts or company) exists
	 *
	 * @return bool
	 */
	public function isClientExists(): bool
	{
		return $this->companyId || $this->contactIds;
	}

	/**
	 * Create instance by owner values.
	 *
	 * For some entities, owner values will also be added automatically.
	 * If you need to use them, you can set them manually.
	 *
	 * @param int $ownerTypeId
	 * @param int $ownerId
	 *
	 * @return self
	 */
	public static function createFromOwner(int $ownerTypeId, int $ownerId): self
	{
		$instance = self::getFromCache($ownerTypeId, $ownerId);
		if ($instance !== null)
		{
			return $instance;
		}

		$withOwner = false;

		$companyId = null;
		$contactIds = [];

		if ($ownerTypeId === CCrmOwnerType::Lead)
		{
			$lead = Container::getInstance()
				->getFactory(CCrmOwnerType::Lead)
				?->getItem($ownerId, [
					Item::FIELD_NAME_ID,
					Item::FIELD_NAME_COMPANY_ID,
					Item::FIELD_NAME_CONTACT_IDS,
				])
			;

			if ($lead !== null)
			{
				$companyId = $lead->getCompanyId();
				$contactIds = $lead->getContactIds();
			}
		}
		elseif ($ownerTypeId === CCrmOwnerType::Deal)
		{
			$deal = Container::getInstance()
				->getFactory(CCrmOwnerType::Deal)
				?->getItem($ownerId, [
					Item::FIELD_NAME_ID,
					Item::FIELD_NAME_COMPANY_ID,
					Item::FIELD_NAME_CONTACT_IDS,
				])
			;

			if ($deal !== null)
			{
				$companyId = $deal->getCompanyId();
				$contactIds = $deal->getContactIds();
				$withOwner = true;
			}
		}
		elseif ($ownerTypeId === CCrmOwnerType::Contact)
		{
			$contactIds = [(int)$ownerId];
		}
		elseif ($ownerTypeId === CCrmOwnerType::Company)
		{
			$companyId = (int)$ownerId;
		}
		elseif ($ownerTypeId === CCrmOwnerType::Order)
		{
			$order = Order::load($ownerId);
			if ($order)
			{
				$collection = $order->getContactCompanyCollection();
				$company = $collection->getPrimaryCompany();
				if ($company)
				{
					$companyId = (int)$company->getField('ENTITY_ID');
				}

				$contacts = $collection->getContacts();
				foreach ($contacts as $contact)
				{
					$contactIds[] = (int)$contact->getField('ENTITY_ID');
				}
			}
		}
		elseif ($ownerTypeId === CCrmOwnerType::Invoice)
		{
			$invoiceList = CCrmInvoice::getList(
				arFilter: [
					'ID' => $ownerId,
				],
				arSelectFields: [
					'ID',
					'UF_COMPANY_ID',
					'UF_CONTACT_ID',
				],
			);

			$invoice = null;
			if (is_object($invoiceList))
			{
				$invoice = $invoiceList->Fetch();
			}

			if ($invoice)
			{
				$companyID = $invoice['UF_COMPANY_ID'] ?? 0;
				if ($companyID)
				{
					$companyId = $companyID;
				}

				$contactID = $invoice['UF_CONTACT_ID'] ?? 0;
				if ($contactID)
				{
					$contactIds[] = $contactID;
				}
			}
		}
		else
		{
			$factory = Container::getInstance()->getFactory($ownerTypeId);
			if ($factory)
			{
				$item = $factory->getItem($ownerId, [
					Item::FIELD_NAME_ID,
					Item::FIELD_NAME_COMPANY_ID,
					Item::FIELD_NAME_CONTACT_ID,
				]);

				if ($item && $item->getCompanyId())
				{
					$companyId = (int)$item->getCompanyId();
				}

				if ($item && $item->getContactId())
				{
					$contactIds = [(int)$item->getContactId()];
				}
			}
			$withOwner = true;
		}

		$self = new static(
			$companyId,
			$contactIds
		);

		if ($withOwner)
		{
			$self->ownerId = $ownerId;
			$self->ownerTypeId = $ownerTypeId;
		}

		self::setToCache($ownerTypeId, $ownerId, $self);

		return $self;
	}

	private static function getFromCache(int $ownerTypeId, int $ownerId): ?self
	{
		return self::$cache[$ownerTypeId][$ownerId] ?? null;
	}

	private static function setToCache(int $ownerTypeId, int $ownerId, self $instance): void
	{
		self::$cache[$ownerTypeId][$ownerId] = $instance;
	}
}
