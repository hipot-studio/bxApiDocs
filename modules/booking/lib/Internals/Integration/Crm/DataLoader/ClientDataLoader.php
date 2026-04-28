<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Integration\Crm\DataLoader;

use Bitrix\Booking\Internals\Integration\Crm\ClientDataProvider;
use Bitrix\Main\Loader;
use CCrmOwnerType;

class ClientDataLoader
{
	public function __construct(private readonly ClientDataProvider $clientDataProvider)
	{
	}

	public function loadDataForCollection(...$clientCollections): void
	{
		if (!Loader::includeModule('crm'))
		{
			return;
		}

		$contactIds = [];
		$companyIds = [];

		foreach ($clientCollections as $clientCollection)
		{
			foreach ($clientCollection as $client)
			{
				$clientId = $client->getId();
				$clientTypeCode = $client->getType()?->getCode();

				if ($clientTypeCode === CCrmOwnerType::ContactName)
				{
					$contactIds[$clientId] = $clientId;
				}

				if ($clientTypeCode === CCrmOwnerType::CompanyName)
				{
					$companyIds[$clientId] = $clientId;
				}
			}
		}

		$clientData = [
			CCrmOwnerType::ContactName => $this->clientDataProvider->getContactsByIds($contactIds),
			CCrmOwnerType::CompanyName => $this->clientDataProvider->getCompaniesByIds($companyIds),
		];

		foreach ($clientCollections as $clientCollection)
		{
			foreach ($clientCollection as $client)
			{
				$clientId = $client->getId();
				$clientTypeCode = $client->getType()?->getCode();

				if ($clientTypeCode === CCrmOwnerType::ContactName)
				{
					if (isset($clientData[CCrmOwnerType::ContactName][$clientId]))
					{
						$client->setData($clientData[CCrmOwnerType::ContactName][$clientId]);
					}
				}

				if ($clientTypeCode === CCrmOwnerType::CompanyName)
				{
					if (isset($clientData[CCrmOwnerType::CompanyName][$clientId]))
					{
						$client->setData($clientData[CCrmOwnerType::CompanyName][$clientId]);
					}
				}
			}
		}
	}
}
