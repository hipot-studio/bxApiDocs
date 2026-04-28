<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Integration\Crm;

use Bitrix\Booking\Entity\Booking\Booking;
use Bitrix\Booking\Entity\Client\Client;
use Bitrix\Booking\Entity\Client\ClientType;
use Bitrix\Booking\Entity\ExternalData\ExternalDataItem;
use Bitrix\Booking\Entity\Client\ClientCollection;
use Bitrix\Booking\Entity\ExternalData\ExternalDataCollection;
use Bitrix\Crm\Item;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\Loader;
use CCrmOwnerType;

class DealClientSynchronizer
{
	private const MODULE_ID = 'crm';

	public function __construct(
		private readonly DealDataProvider $dealDataProvider,
		private readonly ExternalDataItemExtractor $externalDataExtractor,
	)
	{
	}

	public function setClientsFromDeal(ClientCollection $clientCollection, ...$externalDataCollections): void
	{
		if (!Loader::includeModule('crm'))
		{
			return;
		}

		if (!$clientCollection->isEmpty())
		{
			return;
		}

		$dealIds = $this->externalDataExtractor->getDealIdsFromCollections($externalDataCollections);
		if (empty($dealIds))
		{
			return;
		}

		$deals = $this->dealDataProvider->getByIds($dealIds);
		if (empty($deals))
		{
			return;
		}

		/** @var ExternalDataCollection $externalDataCollection */
		foreach ($externalDataCollections as $externalDataCollection)
		{
			/** @var ExternalDataItem $externalDataItem */
			foreach ($externalDataCollection as $externalDataItem)
			{
				$dealId = $this->externalDataExtractor->getDealId($externalDataItem);
				if ($dealId === null || !isset($deals[$dealId]))
				{
					continue;
				}

				/** @var Item\Deal $deal */
				$deal = $deals[$dealId];

				foreach ($deal->getContacts() as $contact)
				{
					$contactType = (new ClientType())
						->setModuleId(self::MODULE_ID)
						->setCode(CCrmOwnerType::ContactName)
					;

					$contact = (new Client())
						->setId($contact->getId())
						->setType($contactType)
					;

					$clientCollection->add($contact);
				}

				$company = $deal->getCompany();

				if ($company)
				{
					$contactType = (new ClientType())
						->setModuleId(self::MODULE_ID)
						->setCode(CCrmOwnerType::CompanyName)
					;

					$contact = (new Client())
						->setId($company->getId())
						->setType($contactType)
					;

					$clientCollection->add($contact);
				}
			}
		}
	}

	public function setClientToDeal(Booking $updatedBooking, Booking $prevBooking): void
	{
		if (!Loader::includeModule('crm'))
		{
			return;
		}

		$isNewClientLinked = $prevBooking->getPrimaryClient() === null && $updatedBooking->getPrimaryClient() !== null;
		$dealIds = $this->externalDataExtractor->getDealIdsFromCollections([$updatedBooking->getExternalDataCollection()]);
		$prevDealIds = $this->externalDataExtractor->getDealIdsFromCollections([$prevBooking->getExternalDataCollection()]);
		$hasDealBindingChanged = !empty(array_diff($dealIds, $prevDealIds));

		if (!($isNewClientLinked || $hasDealBindingChanged))
		{
			return;
		}

		$clients = $updatedBooking->getClientCollection();

		if ($clients->isEmpty())
		{
			return;
		}

		$dealIds = $this->externalDataExtractor->getDealIdsFromCollections([$updatedBooking->getExternalDataCollection()]);

		if (empty($dealIds))
		{
			return;
		}

		$deals = $this->dealDataProvider->getByIds($dealIds);

		if (empty($deals))
		{
			return;
		}

		/** @var ExternalDataItem $externalDataItem */
		foreach ($updatedBooking->getExternalDataCollection() as $externalDataItem)
		{
			$dealId = $this->externalDataExtractor->getDealId($externalDataItem);

			if ($dealId === null || !isset($deals[$dealId]))
			{
				continue;
			}

			/** @var Item\Deal $deal */
			$deal = $deals[$dealId];

			self::bindDealContacts($deal, $clients);
		}
	}

	private static function bindDealContacts(Item\Deal $deal, ClientCollection $clients): void
	{
		$isDealToUpdate = false;

		// setting contacts
		if (empty($deal->getContacts()))
		{
			$contactIds = [];

			foreach ($clients as $client)
			{
				if ($client->getType()->getModuleId() !== self::MODULE_ID)
				{
					continue;
				}

				if ($client->getType()->getCode() !== CCrmOwnerType::ContactName)
				{
					continue;
				}

				$contactIds[] = $client->getId();
			}

			if (!empty($contactIds))
			{
				$deal->setContactIds($contactIds);
				$isDealToUpdate = true;
			}
		}

		// setting company
		if ($deal->getCompany() === null)
		{
			foreach ($clients as $client)
			{
				if (
					$client->getType()->getModuleId() === self::MODULE_ID
					&& $client->getType()->getCode() === CCrmOwnerType::CompanyName
				)
				{
					$deal->setCompanyId($client->getId());
					$isDealToUpdate = true;
					break;
				}
			}
		}

		if ($isDealToUpdate)
		{
			$factory = Container::getInstance()->getFactory(CCrmOwnerType::Deal);
			$factory->getUpdateOperation($deal)
				->disableCheckFields()
				->disableCheckRequiredUserFields()
				->launch();;
		}
	}
}
