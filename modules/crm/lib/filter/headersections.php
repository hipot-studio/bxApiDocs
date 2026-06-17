<?php

namespace Bitrix\Crm\Filter;

use Bitrix\Crm\Component\EntityList\ClientDataProvider;
use Bitrix\Crm\Service;
use Bitrix\Crm\Filter;
use Bitrix\Crm\Traits;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Localization\Loc;
use CCrmOwnerType;

final class HeaderSections
{
	use Traits\Singleton;

	public function filterGridSupportedSections(array $sections): array
	{
		return array_filter($sections, fn ($section) => ($section['grid'] ?? true) === true);
	}

	public function sections(?Service\Factory $factory): array
	{
		if ($factory === null)
		{
			return [];
		}

		$primaryEntitySection = [
			'id' => $factory->getEntityName(),
			'name' => $factory->getEntityDescription(),
			'default' => true,
			'selected' => true,
		];

		return $this->makeSections(
			$primaryEntitySection,
			$factory,
		);
	}

	public function additionalProviders(EntitySettings $settings, Filter\Factory $filterFactory): array
	{
		$additionalProviders = [];
		if (!($settings instanceof TimelineSettings))
		{
			$additionalProviders[] = $filterFactory->getUserFieldDataProvider($settings);
		}
		if ($settings instanceof ContactSettings || $settings instanceof CompanySettings)
		{
			$additionalProviders[] = $filterFactory->getRequisiteDataProvider($settings);
		}
		$additionalProviders = array_merge(
			$additionalProviders,
			$filterFactory->getSupportedClientDataProviders($settings),
		);

		if ($this->isFastSearchCanBeUsed($settings))
		{
			$additionalProviders[] = new ActivityFastSearchDataProvider(
				new ActivityFastSearchSettings([
					'ID' => $settings->getID(),
					'PARENT_FILTER_ENTITY_TYPE_ID' => $settings->getEntityTypeID(),
					'PARENT_ENTITY_DATA_PROVIDER' => $filterFactory->getDataProvider($settings),
				])
			);
		}

		return $additionalProviders;
	}

	private function makeSections(array $primaryEntitySection, Service\Factory $factory): array
	{
		$sections = [$primaryEntitySection];
		$entityTypeId = $factory->getEntityTypeId();

		if ($factory->isClientFieldsEnabled())
		{
			$hasCompany = $factory->isClientCompanyEnabled();
			$sections = array_merge($sections, $this->makeClientFieldsSections($hasCompany));
		}

		if (
			$this->isActivitySearchSupported($entityTypeId)
			&& Option::get('crm', 'enable_act_fastsearch_filter', 'Y') === 'Y'
		)
		{
			$sections[] = $this->headerSectionFastSearch();
		}

		return $sections;
	}

	private function makeClientFieldsSections(bool $hasCompany): array
	{
		$sections = [];

		$contactsSection = [
			'id' => CCrmOwnerType::ContactName,
			'name' => Loc::getMessage('CRM_HEADER_SECTION_CONTACT'),
			'selected' => true,
		];
		if (!$hasCompany)
		{
			return [$contactsSection];
		}

		$companiesSection = [
			'id' => CCrmOwnerType::CompanyName,
			'name' =>  Loc::getMessage('CRM_HEADER_SECTION_COMPANY'),
			'selected' => true,
		];
		if (ClientDataProvider::getPriorityEntityTypeId() === CCrmOwnerType::Contact)
		{
			$sections[] = $contactsSection;
			$sections[] = $companiesSection;
		}
		else
		{
			$sections[] = $companiesSection;
			$sections[] = $contactsSection;
		}

		return $sections;
	}

	private function headerSectionFastSearch(): array
	{
		return [
			'id' => 'ACTIVITY_FASTSEARCH',
			'name' =>  Loc::getMessage('CRM_HEADER_SECTION_ACTIVITY_FASTSEARCH'),
			'selected' => true,
			'grid' => false
		];
	}

	private function isFastSearchCanBeUsed(EntitySettings $settings): bool
	{
		$myCompanyMode = $settings instanceof CompanySettings && $settings->isMyCompanyMode();
		if ($myCompanyMode)
		{
			return false;
		}

		return $this->isActivitySearchSupported($settings->getEntityTypeID());
	}

	private function isActivitySearchSupported(int $entityTypeId): bool
	{
		$list = [
			CCrmOwnerType::Lead,
			CCrmOwnerType::Deal,
			CCrmOwnerType::Contact,
			CCrmOwnerType::Company,
			CCrmOwnerType::Quote,
			CCrmOwnerType::SmartInvoice,
		];

		return in_array($entityTypeId, $list) || CCrmOwnerType::isPossibleDynamicTypeId($entityTypeId);
	}
}