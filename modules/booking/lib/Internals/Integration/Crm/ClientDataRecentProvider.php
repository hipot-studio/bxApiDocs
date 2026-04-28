<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Integration\Crm;

use Bitrix\Main\Loader;
use CCrmOwnerType;

class ClientDataRecentProvider
{
	public function __construct(private readonly ClientDataProvider $clientDataProvider)
	{
	}

	public function getClientDataRecent(): array
	{
		if (!Loader::includeModule('crm'))
		{
			return [];
		}

		$contactIds = [];
		$companyIds = [];

		//@todo why do we take it from deal?
		$lastContacts = \CUserOptions::GetOption('crm.deal.details', 'contact', []);
		$lastCompanies = \CUserOptions::GetOption('crm.deal.details', 'company', []);
		foreach ($lastContacts as $lastContact)
		{
			$parts = explode(':', $lastContact);
			$contactId = (int)($parts[1] ?? 0);
			if (!empty($contactId))
			{
				$contactIds[$contactId] = $contactId;
			}
		}

		foreach ($lastCompanies as $lastCompany)
		{
			$parts = explode(':', $lastCompany);
			$companyId = (int)($parts[1] ?? 0);
			if (!empty($companyId))
			{
				$companyIds[$companyId] = $companyId;
			}
		}

		return [
			CCrmOwnerType::ContactName => $this->clientDataProvider->getContactsByIds($contactIds),
			CCrmOwnerType::CompanyName => $this->clientDataProvider->getCompaniesByIds($companyIds),
		];
	}
}
