<?php

namespace Bitrix\Crm\Import\Factory;

use Bitrix\Crm\Import\Contract\ImportEntityInterface;
use Bitrix\Crm\Import\Dto\Entity\AbstractImportSettings;
use Bitrix\Crm\Import\Dto\Entity\ImportSettings\CompanyImportSettings;
use Bitrix\Crm\Import\Dto\Entity\ImportSettings\ContactImportSettings;
use Bitrix\Crm\Import\Dto\Entity\ImportSettings\DealImportSettings;
use Bitrix\Crm\Import\Dto\Entity\ImportSettings\DynamicImportSettings;
use Bitrix\Crm\Import\Dto\Entity\ImportSettings\LeadImportSettings;
use Bitrix\Crm\Import\Dto\Entity\ImportSettings\QuoteImportSettings;
use Bitrix\Crm\Import\Dto\Entity\ImportSettings\SmartInvoiceImportSettings;
use Bitrix\Crm\Import\Enum\Contact\Origin;
use Bitrix\Crm\Import\ImportEntities\Company;
use Bitrix\Crm\Import\ImportEntities\Contact;
use Bitrix\Crm\Import\ImportEntities\Contact\GmailContact;
use Bitrix\Crm\Import\ImportEntities\Contact\OutlookContact;
use Bitrix\Crm\Import\ImportEntities\Contact\VCardContact;
use Bitrix\Crm\Import\ImportEntities\Contact\YahooContact;
use Bitrix\Crm\Import\ImportEntities\Deal;
use Bitrix\Crm\Import\ImportEntities\Dynamic;
use Bitrix\Crm\Import\ImportEntities\Lead;
use Bitrix\Crm\Import\ImportEntities\Quote;
use Bitrix\Crm\Import\ImportEntities\SmartInvoice;
use CCrmOwnerType;

final class ImportEntityFactory
{
	public function createEntity(int $entityTypeId, array $importSettingsRaw): ?ImportEntityInterface
	{
		$importSettings = $this->createImportSettings($entityTypeId)?->fill($importSettingsRaw);
		if ($importSettings === null)
		{
			return null;
		}

		return $this->createEntityBySettings($importSettings);
	}

	public function createEntityBySettings(AbstractImportSettings $importSettings): ?ImportEntityInterface
	{
		$entity = match (true) {
			$importSettings instanceof LeadImportSettings => new Lead($importSettings),
			$importSettings instanceof DealImportSettings => new Deal($importSettings),
			$importSettings instanceof CompanyImportSettings => new Company($importSettings),
			$importSettings instanceof QuoteImportSettings => new Quote($importSettings),
			$importSettings instanceof SmartInvoiceImportSettings => new SmartInvoice($importSettings),
			$importSettings instanceof DynamicImportSettings => new Dynamic($importSettings),
			default => null,
		};

		if ($entity === null && $importSettings instanceof ContactImportSettings)
		{
			return match ($importSettings->getOrigin()) {
				Origin::VCard => new VCardContact($importSettings),
				Origin::Gmail => new GmailContact($importSettings),
				Origin::Outlook => new OutlookContact($importSettings),
				Origin::Yahoo => new YahooContact($importSettings),

				default => new Contact($importSettings),
			};
		}

		return $entity;
	}

	public function createImportSettings(int $entityTypeId): ?AbstractImportSettings
	{
		$importSettings = match ($entityTypeId) {
			CCrmOwnerType::Lead => new LeadImportSettings(),
			CCrmOwnerType::Deal => new DealImportSettings(),
			CCrmOwnerType::Contact => new ContactImportSettings(),
			CCrmOwnerType::Company => new CompanyImportSettings(),
			CCrmOwnerType::Quote => new QuoteImportSettings(),
			CCrmOwnerType::SmartInvoice => new SmartInvoiceImportSettings(),
			default => null,
		};

		if ($importSettings === null && CCrmOwnerType::isPossibleDynamicTypeId($entityTypeId))
		{
			return new DynamicImportSettings($entityTypeId);
		}

		return $importSettings;
	}
}
